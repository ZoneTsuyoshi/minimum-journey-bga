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
 * 探査フェイズ（状態 40〜48。仕様書 006 §6、001 §6）の DB を使う部分。
 * BGA の Table には依存せず、送る通知は戻り値で返す（状態クラスが送る）。結合テストで直接動かす。
 * 探査時・探査中・獲得時の効果（I-11）と探査力変動（I-10）は差し込み口のみ。
 *
 * @phpstan-import-type Targets from ExploreRules
 */
final class ExplorePhase
{
    public const SOURCE_ROW = 'row';
    public const SOURCE_UNKNOWN = 'unknown';
    public const SOURCE_DECK = 'deck';

    /** @var callable(int): string */
    private $playerName;

    /**
     * @param callable(int): string $playerName プレイヤー名の取得
     */
    public function __construct(
        private readonly CardRepository $cards,
        private readonly PlayerValues $players,
        private readonly GlobalValues $globals,
        private readonly ExploreOutputCalculator $calculator,
        callable $playerName,
    ) {
        $this->playerName = $playerName;
    }

    /**
     * 状態 40。探査出力（半減あり）を全員分確定して以後固定し、探査手番を決める。
     *
     * @param list<int> $seatOrder
     */
    public function start(array $seatOrder): ExploreStepResult
    {
        $outputs = [];
        $prepCounts = [];
        foreach ($seatOrder as $playerId) {
            $halved = $this->players->isHalved($playerId);
            $outputs[$playerId] = $this->calculator->decided($playerId, $halved);
            $prepCounts[$playerId] = $this->cards->count(Zone::Prep, $playerId);
            $this->players->setExploreOutput($playerId, $outputs[$playerId]);
        }

        $charm = $this->cards->list(Zone::Charm)[0] ?? null;
        $order = ExploreOrder::decide($seatOrder, $prepCounts, $outputs, $charm?->locationArg);

        $this->globals->setPhase(GlobalValues::PHASE_EXPLORE);
        $this->globals->setExploreOrder($order);
        $this->globals->setExploreIndex(0);
        $this->globals->setExploreState(null);

        $notifications = [new Notification(null, 'exploreStarted', clienttranslate('The exploration phase begins'), [])];
        foreach ($seatOrder as $playerId) {
            $notifications[] = new Notification(
                null,
                'exploreOutputDecided',
                clienttranslate('${player_name} has an exploration output of ${output}'),
                [
                    'player_id' => $playerId,
                    'player_name' => ($this->playerName)($playerId),
                    'output' => $outputs[$playerId],
                    'halved' => $this->players->isHalved($playerId),
                ],
            );
        }
        $notifications[] = new Notification(null, 'exploreOrderDecided', clienttranslate('Exploration order: ${order_names}'), [
            'order' => $order,
            'order_names' => implode(', ', array_map($this->playerName, $order)),
        ]);

        return new ExploreStepResult('start', $notifications);
    }

    /**
     * 状態 41。次の手番のプレイヤーを決めて手番の一時データを初期化する。全員終わっていれば allDone。
     */
    public function beginTurn(): ExploreStepResult
    {
        $index = $this->globals->exploreIndex();
        $playerId = RoundFlow::currentExplorer($this->globals->exploreOrder(), $index);
        if ($playerId === null) {
            return new ExploreStepResult('allDone', []);
        }

        $this->globals->setExploreState([
            'player_id' => $playerId,
            'sum_cost' => 0,
            'explored' => 0,
            'used_on_explore' => [],
            'additional_done' => false,
            'cost_mod' => 0,
        ]);

        return new ExploreStepResult('choose', [
            new Notification(null, 'exploreTurnStarted', clienttranslate('${player_name} starts the exploration turn'), [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'index' => $index,
            ]),
        ], $playerId);
    }

    /**
     * 探査できる対象（006 §6.1 の ExploreRules.targets）。判定はサーバーだけが行う。
     * 山札の上は、枚数とコストだけを返し、カードの ID や券面は返さない（001 §6-3-1）。
     *
     * @return Targets
     */
    public function targets(int $playerId): array
    {
        $rowCards = [];
        $deckCounts = [];
        $locked = $this->globals->lockedCard();
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            foreach ($this->cards->list(Zone::Row, $cost) as $card) {
                if ($locked !== null && $locked['card_id'] === $card->id && $locked['player_id'] !== $playerId) {
                    continue; // 007 が保持した列のカードは、持ち主以外は探査できない（R27）。山札の上の探査は制限しない
                }
                $rowCards[] = ['cardId' => $card->id, 'slot' => $card->order, 'cost' => $cost];
            }
            $deckCounts[$cost] = $this->cards->count(Zone::ExploreDeck, $cost);
        }
        $unknown = $this->cards->list(Zone::Unknown)[0] ?? null;

        return ExploreRules::targets(
            $this->players->exploreOutput($playerId) ?? 0,
            $rowCards,
            $unknown?->id,
            $deckCounts,
        );
    }

    /**
     * 状態 43 への入場。探査できる対象がなければ自動パスする（transition = passed。ログに出す）。
     */
    public function enterChoose(int $playerId): ExploreStepResult
    {
        if (ExploreRules::isEmpty($this->targets($playerId))) {
            return new ExploreStepResult('passed', [$this->passNotification($playerId, true)]);
        }
        return new ExploreStepResult(null, []);
    }

    /**
     * 探査する（actExplore の確定）。山札の上は、この時点でカードを公開して獲得し、取り消せない。
     * 獲得時効果（I-11）は呼ばない。未確認帯域の獲得とコスト 8 の山札の枯渇では終了フラグを立てる。
     *
     * @throws ExploreRuleViolation
     */
    public function explore(int $playerId, string $source, int $cardId, int $cost): ExploreStepResult
    {
        $state = $this->globals->exploreState();
        if ($state === null || $state['player_id'] !== $playerId) {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_YOUR_TURN);
        }
        if ($state['explored'] > 0) {
            throw new ExploreRuleViolation(ExploreRuleViolation::ALREADY_EXPLORED); // 追加探査は I-11
        }

        $targets = $this->targets($playerId);
        $slot = null;
        $effectiveCost = 0;
        $pileCost = null;
        switch ($source) {
            case self::SOURCE_ROW:
                $found = array_values(array_filter($targets['rowTargets'], static fn(array $t): bool => $t['cardId'] === $cardId));
                if ($found === []) {
                    throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
                }
                $card = $this->cards->get($cardId);
                $slot = $found[0]['slot'];
                $effectiveCost = $found[0]['effectiveCost'];
                break;
            case self::SOURCE_UNKNOWN:
                $unknown = $targets['unknownTarget'];
                if ($unknown === null || $unknown['cardId'] !== $cardId) {
                    throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
                }
                $card = $this->cards->get($cardId);
                $effectiveCost = $unknown['effectiveCost'];
                break;
            case self::SOURCE_DECK:
                $found = array_values(array_filter($targets['deckTargets'], static fn(array $t): bool => $t['cost'] === $cost));
                if ($found === []) {
                    throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
                }
                $card = $this->cards->peekTop(Zone::ExploreDeck, $cost, 1)[0];
                $effectiveCost = $found[0]['effectiveCost'];
                $pileCost = $cost;
                break;
            default:
                throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
        }

        $this->cards->moveToAcquired($card, $playerId);
        $state['explored']++;
        $state['sum_cost'] += $effectiveCost;
        $this->globals->setExploreState($state);

        $name = self::cardName($card);
        $notifications = [
            new Notification(null, 'cardAcquired', clienttranslate('${player_name} explores ${card_name}'), [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card' => self::cardView($card),
                'card_name' => $name,
                'card_no' => $card->cardNo,
                'from' => $source,
                'cost' => $pileCost ?? $effectiveCost,
                'slot' => $slot,
                'free' => false,
                'additional' => false,
                'i18n' => ['card_name'],
            ]),
        ];

        // 終了フラグだけ立てる。その場では終了せず、残りの探査手番と帰還フェイズを行う（001 Q-18、006 §6.4）
        if ($source === self::SOURCE_UNKNOWN) {
            $this->globals->setUnknownAcquirer($playerId);
            array_push($notifications, ...$this->triggerEnd(GlobalValues::END_UNKNOWN, $playerId));
        }
        if ($pileCost === 8 && $this->cards->count(Zone::ExploreDeck, 8) === 0) {
            array_push($notifications, ...$this->triggerEnd(GlobalValues::END_PILE8, 0));
        }

        $counters = ['players' => [$playerId => ['acquiredCount' => $this->cards->count(Zone::Acquired, $playerId)]]];
        if ($pileCost !== null) {
            $counters['piles'] = [$pileCost => $this->cards->count(Zone::ExploreDeck, $pileCost)];
        }
        $notifications[] = new Notification(null, 'countersUpdate', '', $counters);

        // 獲得時効果の振り分けは状態 44（I-11 の差し込み口）
        return new ExploreStepResult('acquired', $notifications);
    }

    /**
     * パスする（actPass と zombie）。
     */
    public function pass(int $playerId): ExploreStepResult
    {
        return new ExploreStepResult('passed', [$this->passNotification($playerId, false)]);
    }

    /**
     * @return list<Notification>
     */
    private function triggerEnd(string $reason, int $playerId): array
    {
        return GameEndTrigger::trigger($this->globals, $this->playerName, $reason, $playerId);
    }

    private function passNotification(int $playerId, bool $auto): Notification
    {
        return new Notification(
            null,
            'explorePassed',
            $auto
                ? clienttranslate('${player_name} has nothing to explore and passes automatically')
                : clienttranslate('${player_name} passes'),
            [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'auto' => $auto,
            ],
        );
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
