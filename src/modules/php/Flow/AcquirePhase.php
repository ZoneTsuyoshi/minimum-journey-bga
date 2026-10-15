<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\EffectKind;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use Bga\Games\MinimumJourney\Material\Symbol;
use Bga\Games\MinimumJourney\Setup\GameSetup;

/**
 * 獲得時の効果（状態 44〜47。仕様書 008 §5、006 §6）の DB を使う部分。
 * BGA の Table には依存せず、送る通知は戻り値で返す。結合テストで直接動かす。
 *
 * 獲得したカードを解決待ちの列（globals の acquire_queue）に積み、状態 44 が先頭から 1 枚ずつ解決する。
 * 選択が要る効果（004・034・055）は先頭を残したまま状態 45・46・47 へ出て、アクションで先頭を取り除く。
 * 034 の無料獲得は、新しい獲得を先頭に挿入する（入れ子。034 の処理の中で即時に解決する）。
 */
final class AcquirePhase
{
    private const CARD_COLONY_CAMP = 4;
    private const CARD_DUST_VACUUM = 34;
    private const CARD_BLACK_HOLE = 55;

    /** @var callable(int): string */
    private $playerName;
    private readonly CardRemoval $removal;

    /**
     * @param callable(int): string $playerName
     */
    public function __construct(
        private readonly CardRepository $cards,
        private readonly GlobalValues $globals,
        callable $playerName,
    ) {
        $this->playerName = $playerName;
        $this->removal = new CardRemoval($cards, $playerName);
    }

    // ---- 列 ----

    /**
     * 獲得したカードの獲得時効果を解決待ちに積む。$front なら先頭（入れ子）。
     */
    public function enqueue(int $cardId, int $playerId, bool $front = false): void
    {
        $queue = $this->globals->acquireQueue();
        $entry = ['card_id' => $cardId, 'player_id' => $playerId];
        if ($front) {
            array_unshift($queue, $entry);
        } else {
            $queue[] = $entry;
        }
        $this->globals->setAcquireQueue($queue);
    }

    public function hasPending(): bool
    {
        return $this->globals->acquireQueue() !== [];
    }

    /**
     * 先頭の解決待ち（なければ null）。
     *
     * @return array{card_id: int, player_id: int}|null
     */
    public function current(): ?array
    {
        return $this->globals->acquireQueue()[0] ?? null;
    }

    private function dropCurrent(): void
    {
        $queue = $this->globals->acquireQueue();
        array_shift($queue);
        $this->globals->setAcquireQueue($queue);
    }

    // ---- 状態 44 ----

    /**
     * 解決待ちの先頭から、選択が要る効果に当たるまで自動で進める。
     * 選択が要るなら遷移（exclude／freeAcquire／blackHole）と手番のプレイヤーを返し、尽きたら finished。
     */
    public function next(): ExploreStepResult
    {
        while (($entry = $this->current()) !== null) {
            $card = $this->cards->get($entry['card_id']);
            $player = $entry['player_id'];
            $effect = CardDefinitions::get(sprintf('%03d', $card->cardNo))?->effectsFor(EffectTiming::OnAcquire)[0] ?? null;
            if ($effect === null) {
                $this->dropCurrent();
                continue;
            }
            switch ($effect->kind) {
                case EffectKind::MarkTopAfterShuffle:
                    // 040: 探査フェイズでの獲得のみ記録する（準備フェイズの 036 では記録しない。002 No.45）
                    if ($this->globals->phase() === GlobalValues::PHASE_EXPLORE) {
                        $records = $this->globals->topAfterShuffle();
                        $records[] = ['player_id' => $player, 'card_no' => $card->cardNo];
                        $this->globals->setTopAfterShuffle($records);
                    }
                    $this->dropCurrent();
                    break;
                case EffectKind::AcquireExcludePrep:
                    if ($this->prepCandidates($player) === []) {
                        $this->dropCurrent();
                        break;
                    }
                    return new ExploreStepResult('exclude', [], $player);
                case EffectKind::FreeAcquire:
                    $candidates = $this->freeCandidates($player);
                    if ($candidates['rows'] === [] && $candidates['decks'] === []) {
                        $this->dropCurrent();
                        break;
                    }
                    return new ExploreStepResult('freeAcquire', [], $player);
                case EffectKind::ExcludeFromDeck:
                    if ($this->deckCandidates($player) === []) {
                        $this->dropCurrent();
                        break;
                    }
                    return new ExploreStepResult('blackHole', [], $player);
                default:
                    $this->dropCurrent();
            }
        }
        return new ExploreStepResult('finished', []);
    }

    // ---- 004・058（状態 45・51） ----

    /**
     * 準備場の表向きのカード（横向きを含む。裏向きの別置き場は除く）。046 は付加カードごと 1 つの候補。
     *
     * @return list<Card>
     */
    public function prepCandidates(int $playerId): array
    {
        return $this->cards->list(Zone::Prep, $playerId);
    }

    /**
     * 004（状態 45）。必ず 1 枚を除外する。
     *
     * @throws ExploreRuleViolation
     */
    public function excludePrepRequired(int $playerId, int $cardId): ExploreStepResult
    {
        $this->assertCurrent($playerId, self::CARD_COLONY_CAMP);
        $card = $this->pickPrep($playerId, $cardId);
        $notifications = [$this->removal->remove($playerId, $card, 'Colony Camp'), $this->prepCount($playerId)];
        $this->dropCurrent();

        return new ExploreStepResult('done', $notifications);
    }

    /**
     * 058（状態 51）。最大 1 枚（null は除外しない）。お守りの所有権の移動直後（帰還フェイズ①）なので列は使わない。
     *
     * @throws ExploreRuleViolation
     */
    public function excludePrepOptional(int $playerId, ?int $cardId): ExploreStepResult
    {
        $holder = $this->cards->list(Zone::Charm)[0] ?? null;
        if ($holder === null || $holder->locationArg !== $playerId) {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_YOUR_TURN);
        }
        $notifications = [];
        if ($cardId !== null && $cardId > 0) {
            $card = $this->pickPrep($playerId, $cardId);
            $notifications = [$this->removal->remove($playerId, $card, 'Misfortune-Warding Charm'), $this->prepCount($playerId)];
        }
        return new ExploreStepResult('done', $notifications);
    }

    /**
     * zombie の 004: 名声が最小のカード（同点は探査力が最小、さらに同点はカード ID 最小。006 §7）。
     */
    public function zombieExcludeRequired(int $playerId): ExploreStepResult
    {
        $best = null;
        foreach ($this->prepCandidates($playerId) as $card) {
            $def = CardDefinitions::get(sprintf('%03d', $card->cardNo));
            $key = [$def->fame ?? 0, $def->power ?? 0, $card->id];
            if ($best === null || $key < $best[0]) {
                $best = [$key, $card];
            }
        }
        if ($best === null) {
            $this->dropCurrent();
            return new ExploreStepResult('done', []);
        }
        return $this->excludePrepRequired($playerId, $best[1]->id);
    }

    /**
     * @throws ExploreRuleViolation
     */
    private function pickPrep(int $playerId, int $cardId): Card
    {
        foreach ($this->prepCandidates($playerId) as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }
        throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
    }

    // ---- 034（状態 46） ----

    /**
     * 034 の候補: 探査場の [太陽系] または [天の川銀河] のみを持つカード。
     * 列のカード（007 で他プレイヤーに保持されたものは除く）と、コスト 2・4 の探査山札の上。
     *
     * @return array{rows: list<Card>, decks: list<int>}
     */
    public function freeCandidates(int $playerId): array
    {
        $locked = $this->globals->lockedCard();
        $rows = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            foreach ($this->cards->list(Zone::Row, $cost) as $card) {
                if ($locked !== null && $locked['card_id'] === $card->id && $locked['player_id'] !== $playerId) {
                    continue;
                }
                $symbols = CardDefinitions::get(sprintf('%03d', $card->cardNo))->symbols ?? [];
                if ($symbols !== [] && self::onlySolarOrMilkyWay($symbols)) {
                    $rows[] = $card;
                }
            }
        }
        $decks = [];
        foreach ([2, 4] as $cost) {
            if ($this->cards->count(Zone::ExploreDeck, $cost) > 0) {
                $decks[] = $cost;
            }
        }
        return ['rows' => $rows, 'decks' => $decks];
    }

    /**
     * @param string $type 'row'（ref = カード ID）か 'deck'（ref = 山札のコスト）
     * @throws ExploreRuleViolation
     */
    public function freeAcquire(int $playerId, string $type, int $ref): ExploreStepResult
    {
        $this->assertCurrent($playerId, self::CARD_DUST_VACUUM);
        $candidates = $this->freeCandidates($playerId);
        $slot = null;
        $cost = 0;
        if ($type === 'row') {
            $card = null;
            foreach ($candidates['rows'] as $each) {
                if ($each->id === $ref) {
                    $card = $each;
                }
            }
            if ($card === null) {
                throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
            }
            $slot = $card->order;
            $cost = $card->locationArg;
        } elseif ($type === 'deck') {
            if (!in_array($ref, $candidates['decks'], true)) {
                throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
            }
            $card = $this->cards->peekTop(Zone::ExploreDeck, $ref, 1)[0];
            $cost = $ref;
        } else {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
        }

        $this->cards->moveToAcquired($card, $playerId);
        $this->dropCurrent();
        $this->enqueue($card->id, $playerId, true); // 獲得した 1 枚の獲得時効果を、その場で連鎖して解決する

        $notifications = [new Notification(
            null,
            'cardAcquired',
            clienttranslate('${player_name} acquires ${card_name} for free'),
            [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card' => DrawEffects::cardView($card),
                'card_name' => DrawEffects::nameOf($card->cardNo),
                'card_no' => $card->cardNo,
                'from' => $type,
                'cost' => $cost,
                'slot' => $slot,
                'free' => true,
                'additional' => false,
                'i18n' => ['card_name'],
            ],
        )];
        $counters = ['players' => [$playerId => ['acquiredCount' => $this->cards->count(Zone::Acquired, $playerId)]]];
        if ($type === 'deck') {
            $counters['piles'] = [$cost => $this->cards->count(Zone::ExploreDeck, $cost)];
        }
        $notifications[] = new Notification(null, 'countersUpdate', '', $counters);

        return new ExploreStepResult('acquired', $notifications);
    }

    /**
     * zombie の 034: 列の候補のうち名声が最大のカード（同点はカード ID 最小）。なければ山札（コスト 2、なければ 4）の上。
     */
    public function zombieFreeAcquire(int $playerId): ExploreStepResult
    {
        $candidates = $this->freeCandidates($playerId);
        $best = null;
        foreach ($candidates['rows'] as $card) {
            $fame = CardDefinitions::get(sprintf('%03d', $card->cardNo))->fame ?? 0;
            if ($best === null || $fame > $best[0] || ($fame === $best[0] && $card->id < $best[1]->id)) {
                $best = [$fame, $card];
            }
        }
        if ($best !== null) {
            return $this->freeAcquire($playerId, 'row', $best[1]->id);
        }
        return $this->freeAcquire($playerId, 'deck', $candidates['decks'][0] ?? 0);
    }

    /**
     * @param list<Symbol> $symbols
     */
    private static function onlySolarOrMilkyWay(array $symbols): bool
    {
        foreach ($symbols as $symbol) {
            if ($symbol !== Symbol::SolarSystem && $symbol !== Symbol::MilkyWay) {
                return false;
            }
        }
        return true;
    }

    // ---- 055（状態 47） ----

    /**
     * 055 の候補: 自分のデッキ全体（山札・準備場・獲得・別置き場の裏向き。お守りを除く）。046 は付加カードごと 1 つの候補。
     * 山の順序を漏らさないよう card_no の昇順（同じなら絵柄）で返す。
     *
     * @return list<Card>
     */
    public function deckCandidates(int $playerId): array
    {
        $cards = [];
        foreach ([Zone::Deck, Zone::Prep, Zone::Acquired, Zone::Facedown] as $zone) {
            foreach ($this->cards->list($zone, $playerId) as $card) {
                $cards[] = $card;
            }
        }
        usort($cards, static fn(Card $a, Card $b): int => [$a->cardNo, $a->art] <=> [$b->cardNo, $b->art]);
        return $cards;
    }

    /**
     * 本人にだけ送るデッキ全体の券面（解決中の 055 のみ。007 §7.5）。付加カードは 046 の中に入れる。
     *
     * @return list<array{id: int, cardNo: int, art: int, zone: string, attached: array{id: int, cardNo: int, art: int}|null}>
     */
    public function deckViewFor(int $playerId): array
    {
        $current = $this->current();
        if ($current === null || $current['player_id'] !== $playerId || $this->cards->get($current['card_id'])->cardNo !== self::CARD_BLACK_HOLE) {
            return [];
        }
        $view = [];
        foreach ($this->deckCandidates($playerId) as $card) {
            $attached = $this->cards->attachedTo($card)[0] ?? null;
            $view[] = DrawEffects::cardView($card) + [
                'zone' => $card->zone->value,
                'attached' => $attached === null ? null : DrawEffects::cardView($attached),
            ];
        }
        return $view;
    }

    /**
     * @param list<int> $cardIds 0〜2 枚（重複なし）
     * @throws ExploreRuleViolation
     */
    public function excludeDeck(int $playerId, array $cardIds): ExploreStepResult
    {
        $this->assertCurrent($playerId, self::CARD_BLACK_HOLE);
        $cardIds = array_values(array_unique($cardIds));
        if (count($cardIds) > 2) {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
        }
        $candidates = [];
        foreach ($this->deckCandidates($playerId) as $card) {
            $candidates[$card->id] = $card;
        }
        $chosen = [];
        foreach ($cardIds as $id) {
            if (!isset($candidates[$id])) {
                throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
            }
            $chosen[] = $candidates[$id];
        }

        $notifications = [];
        foreach ($chosen as $card) {
            $notifications[] = $this->removal->remove($playerId, $card, 'Black Hole Generator');
        }
        if ($chosen !== []) {
            // 解決後は本人のクライアントが覚えている山札・別置き場の ID を使えなくする（007 §7.5）
            $hidden = [...$this->cards->list(Zone::Deck, $playerId), ...$this->cards->list(Zone::Facedown, $playerId)];
            $this->cards->hideIds($hidden);
            $notifications[] = $this->prepCount($playerId);
        }
        $this->dropCurrent();

        return new ExploreStepResult('done', $notifications);
    }

    // ---- 内部 ----

    /**
     * 先頭の解決待ちが、そのプレイヤーの指定のカードの獲得時効果であること。
     *
     * @throws ExploreRuleViolation
     */
    private function assertCurrent(int $playerId, int $cardNo): void
    {
        $current = $this->current();
        if ($current === null || $current['player_id'] !== $playerId || $this->cards->get($current['card_id'])->cardNo !== $cardNo) {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_YOUR_TURN);
        }
    }

    /**
     * 除外後の各置き場の枚数をクライアントへ（公開情報）。
     */
    private function prepCount(int $playerId): Notification
    {
        return new Notification(null, 'countersUpdate', '', ['players' => [$playerId => [
            'prepCount' => $this->cards->count(Zone::Prep, $playerId),
            'deckCount' => $this->cards->count(Zone::Deck, $playerId),
            'facedownCount' => $this->cards->count(Zone::Facedown, $playerId),
            'acquiredCount' => $this->cards->count(Zone::Acquired, $playerId),
        ]]]);
    }
}
