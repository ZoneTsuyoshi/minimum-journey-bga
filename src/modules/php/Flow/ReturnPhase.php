<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PlayerValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Setup\GameSetup;

/**
 * 帰還フェイズ（状態 50・52・53）と最終得点（状態 60）の DB を使う部分（仕様書 006 §6.4、001 §7・§9）。
 * BGA の Table には依存せず、送る通知は戻り値で返す（状態クラスが送る）。結合テストで直接動かす。
 * 058 の獲得時効果と 040 の山札の上への配置もここで扱う（008 §5）。
 */
final class ReturnPhase
{
    private const CARD_MISFORTUNE_CHARM = 58;

    /** @var callable(int): string */
    private $playerName;

    /**
     * @param callable(int): string $playerName プレイヤー名の取得
     */
    public function __construct(
        private readonly CardRepository $cards,
        private readonly PlayerValues $players,
        private readonly GlobalValues $globals,
        callable $playerName,
    ) {
        $this->playerName = $playerName;
    }

    /**
     * 状態 50。お守りを、探査出力が最小のプレイヤー（同点は探査手番が遅い方）へ移す。
     * 出力は探査手番決定に使った最終値（Q-20）。所有者が変わらなければ通知は出さない。
     *
     * @param list<int> $seatOrder
     * @return list<Notification>
     */
    public function moveCharm(array $seatOrder): array
    {
        return $this->moveCharmStep($seatOrder)->notifications;
    }

    /**
     * 状態 50。moveCharm に、058 の獲得時効果の遷移を加えたもの。
     * お守りの面が 058 なら、新所有者（所有者が変わらなくても）の準備場に表向きのカードがあるとき exclude（状態 51）、なければ next。
     *
     * @param list<int> $seatOrder
     */
    public function moveCharmStep(array $seatOrder): ExploreStepResult
    {
        $this->globals->setPhase(GlobalValues::PHASE_RETURN);

        $charm = $this->cards->list(Zone::Charm)[0] ?? null;
        if ($charm === null) {
            return new ExploreStepResult('next', []);
        }
        $outputs = [];
        foreach ($seatOrder as $playerId) {
            $outputs[$playerId] = $this->players->exploreOutput($playerId) ?? 0;
        }
        $order = $this->globals->exploreOrder();
        $newHolder = CharmTransfer::newHolder($outputs, $order === [] ? $seatOrder : $order);

        $notifications = [];
        if ($newHolder !== null && $newHolder !== $charm->locationArg) {
            $this->cards->move($charm, Zone::Charm, $newHolder, 0);
            $notifications[] = new Notification(
                null,
                'amuletMoved',
                clienttranslate('${card_name} moves to ${player_name}'),
                [
                    'player_id' => $newHolder,
                    'player_name' => ($this->playerName)($newHolder),
                    'from_player_id' => $charm->locationArg,
                    'card' => self::cardView($charm),
                    'card_name' => self::cardName($charm),
                    'i18n' => ['card_name'],
                ],
            );
        }
        // 058 の獲得時効果: 新所有者が準備場から最大 1 枚を除外する（所有者が変わらなくても発動。001 §11 No.10）
        $holder = $newHolder ?? $charm->locationArg;
        if ($charm->cardNo === self::CARD_MISFORTUNE_CHARM && $this->cards->count(Zone::Prep, $holder) > 0) {
            return new ExploreStepResult('exclude', $notifications, $holder);
        }
        return new ExploreStepResult('next', $notifications);
    }

    /**
     * 状態 52。各プレイヤーの準備場（付加カードも）・獲得カード・裏向きの別置き場を山札へ戻して山札をシャッフルする。
     * 表向きだったカードは ID を付け替え、通知には古い ID だけを載せる（007 §7.4）。
     * 裏向きのカードの ID は通知に載せない。ラウンド単位の値もここで初期化する。
     *
     * @param list<int> $seatOrder
     * @return list<Notification>
     */
    public function shuffle(array $seatOrder): array
    {
        $gathered = [];
        $hide = [];
        foreach ($seatOrder as $playerId) {
            $gathered[$playerId] = [];
            // 表向きだったカード。046 を動かすと付加カードも一緒に山札へ移る
            $faceUp = [...$this->cards->list(Zone::Prep, $playerId), ...$this->cards->list(Zone::Acquired, $playerId)];
            foreach ($faceUp as $card) {
                foreach ([$card, ...$this->cards->attachedTo($card)] as $each) {
                    $gathered[$playerId][] = $each->id;
                    $hide[] = $each->id;
                }
            }
            foreach ($faceUp as $card) {
                $this->cards->move($card, Zone::Deck, $playerId);
            }
            foreach ($this->cards->list(Zone::Facedown, $playerId) as $card) {
                $this->cards->move($card, Zone::Deck, $playerId);
            }
        }

        $this->cards->hideIds(array_values($this->cards->getMany($hide)));
        $counts = [];
        foreach ($seatOrder as $playerId) {
            $this->cards->shuffleDeck($playerId);
            $counts[$playerId] = $this->cards->count(Zone::Deck, $playerId);
        }
        $beacons = $this->placeBeacons($seatOrder);

        // ラウンド単位の値を初期化する（次のラウンドの 10 でも初期化するが、帰還の後に残さない）
        $this->players->resetRoundValues();
        $this->globals->setExploreOrder([]);
        $this->globals->setExploreIndex(0);
        $this->globals->setExploreState(null);
        $this->globals->setLockedCard(null);

        return [
            new Notification(
                null,
                'cardsGatheredToDecks',
                clienttranslate('All players shuffle their cards into their decks'),
                ['gathered' => $gathered, 'counts' => $counts],
            ),
            ...$beacons,
        ];
    }

    /**
     * 状態 52: 040 先駆者の道標を探査フェイズで獲得していたら、シャッフル後に山札の上へ置く（008 §5.3）。
     * 帰還フェイズ②の前に除外されていれば（055 など）何もしない。記録は消す。
     *
     * @param list<int> $seatOrder
     * @return list<Notification>
     */
    private function placeBeacons(array $seatOrder): array
    {
        $notifications = [];
        foreach ($this->globals->topAfterShuffle() as $record) {
            $card = $this->cards->findByCardNo($record['card_no'])[0] ?? null;
            $inDeck = $card !== null && $card->zone === Zone::Deck && $card->locationArg === $record['player_id'];
            if ($card === null || !$inDeck || !in_array($record['player_id'], $seatOrder, true)) {
                continue;
            }
            $this->cards->putOnTop($card, Zone::Deck, $record['player_id']);
            $notifications[] = new Notification(
                null,
                'beaconPlaced',
                clienttranslate('${player_name} puts ${card_name} on top of their deck'),
                [
                    'player_id' => $record['player_id'],
                    'player_name' => ($this->playerName)($record['player_id']),
                    'card_name' => self::cardName($card),
                    'i18n' => ['card_name'],
                ],
            );
        }
        $this->globals->setTopAfterShuffle([]);

        return $notifications;
    }

    /**
     * 状態 53。列を規定枚数まで探査山札から表向きで補充する（足りなければあるだけ）。
     * 補充後にコスト 8 の探査山札が空なら終了フラグを立てる。遷移は終了フラグがあれば end、なければ nextRound。
     *
     * @param list<int> $seatOrder
     */
    public function refill(array $seatOrder): ExploreStepResult
    {
        $sizes = GameSetup::ROW_SIZES[count($seatOrder)] ?? throw new \InvalidArgumentException('unsupported number of players');

        $notifications = [];
        $piles = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            $need = RowRefill::count(
                $sizes[$cost],
                $this->cards->count(Zone::Row, $cost),
                $this->cards->count(Zone::ExploreDeck, $cost),
            );
            if ($need === 0) {
                continue;
            }
            $slots = $this->cards->rowFillSlots($cost, $need);
            $added = [];
            foreach ($this->cards->peekTop(Zone::ExploreDeck, $cost, $need) as $i => $card) {
                $this->cards->move($card, Zone::Row, $cost, $slots[$i]);
                $added[] = ['slot' => $slots[$i], 'card' => self::cardView($card)];
            }
            $piles[$cost] = $this->cards->count(Zone::ExploreDeck, $cost);
            $notifications[] = new Notification(
                null,
                'rowRefilled',
                clienttranslate('${count} card(s) are added to the ${cost}-cost row'),
                ['cost' => $cost, 'count' => count($added), 'cards' => $added],
            );
        }
        if ($piles !== []) {
            $notifications[] = new Notification(null, 'countersUpdate', '', ['piles' => $piles]);
        }

        // 補充中に尽きた場合も終了条件を満たす（Q-19、E11）。次のラウンドは行わない
        if ($this->cards->count(Zone::ExploreDeck, 8) === 0) {
            array_push($notifications, ...GameEndTrigger::trigger($this->globals, $this->playerName, GlobalValues::END_PILE8, 0));
        }

        return new ExploreStepResult(RoundFlow::afterRefill($this->globals->gameEndTriggered()), $notifications);
    }

    /**
     * 状態 60。全員の最終得点（名声と同点処理の値）を計算する。ゲーム中は playerScore を更新せず、ここで初めて書く。
     *
     * @param list<int> $seatOrder
     */
    public function finalScoring(array $seatOrder): FinalScoringResult
    {
        $charm = $this->cards->list(Zone::Charm)[0] ?? null;
        $unknownAcquirer = $this->globals->unknownAcquirer();

        $scores = [];
        $notifications = [];
        foreach ($seatOrder as $playerId) {
            $score = FinalScorer::score(
                $this->deckCardNos($playerId),
                $charm !== null && $charm->locationArg === $playerId,
                $unknownAcquirer === $playerId,
            );
            $scores[$playerId] = $score;
            $notifications[] = new Notification(
                null,
                'finalScore',
                clienttranslate('${player_name} scores ${fame} fame'),
                [
                    'player_id' => $playerId,
                    'player_name' => ($this->playerName)($playerId),
                    'fame' => $score->fame(),
                    'base' => $score->base,
                    'modifier' => $score->modifier,
                    'charm_bonus' => $score->charmBonus,
                    'unknown_acquired' => $score->unknownAcquired,
                    'symbols' => $score->symbols,
                    'earth' => FinalScorer::earthCount($this->deckCardNos($playerId)),
                    'deck_size' => count($this->deckCardNos($playerId)),
                    // ゲーム終了時だけ、デッキ全体の券面を全員に公開する（007 §8.4、作者指示 2026-10-19）
                    'deck_cards' => (new FameTotals($this->cards, $this->globals))->deckCards($playerId),
                ],
            );
        }

        return new FinalScoringResult($notifications, $scores);
    }

    /**
     * @return list<int>
     */
    private function deckCardNos(int $playerId): array
    {
        return (new FameTotals($this->cards, $this->globals))->deckCardNos($playerId);
    }

    /**
     * @return array{id: int, cardNo: int, art: int}
     */
    private static function cardView(Card $card): array
    {
        return ['id' => $card->id, 'cardNo' => $card->cardNo, 'art' => $card->art];
    }

    private static function cardName(Card $card): string
    {
        return CardDefinitions::get(sprintf('%03d', $card->cardNo))->nameEn ?? '';
    }
}
