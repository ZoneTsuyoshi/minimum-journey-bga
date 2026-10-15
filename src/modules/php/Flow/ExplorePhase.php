<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PlayerValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\EffectKind;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use Bga\Games\MinimumJourney\Material\Symbol;
use Bga\Games\MinimumJourney\Setup\GameSetup;

/**
 * 探査フェイズ（状態 40〜48。仕様書 006 §6、001 §6）の DB を使う部分。
 * BGA の Table には依存せず、送る通知は戻り値で返す（状態クラスが送る）。結合テストで直接動かす。
 * 探査時（011・013）・探査中（補正と禁止、012 の追加探査）の効果もここで扱う。獲得時の効果は AcquirePhase。
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
    private readonly AcquirePhase $acquire;
    private readonly CardRemoval $removal;

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
        $this->acquire = new AcquirePhase($cards, $globals, $playerName);
        $this->removal = new CardRemoval($cards, $playerName);
    }

    public function acquirePhase(): AcquirePhase
    {
        return $this->acquire;
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

        return new ExploreStepResult($this->hasUsableOnExplore($playerId) ? 'onExplore' : 'choose', [
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
     * 追加探査（012）では、探査済みの実効コストの合計を加えて判定する（008 §4.4）。
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
                $rowCards[] = [
                    'cardId' => $card->id,
                    'slot' => $card->order,
                    'cost' => $cost,
                    'symbols' => CardDefinitions::get(sprintf('%03d', $card->cardNo))->symbols ?? [],
                ];
            }
            $deckCounts[$cost] = $this->cards->count(Zone::ExploreDeck, $cost);
        }
        $unknown = $this->cards->list(Zone::Unknown)[0] ?? null;
        $state = $this->globals->exploreState();

        return ExploreRules::targets(
            $this->players->exploreOutput($playerId) ?? 0,
            $rowCards,
            $unknown?->id,
            $deckCounts,
            $this->costContext($playerId),
            $state['sum_cost'] ?? 0,
        );
    }

    /**
     * 探査中の効果が有効なカード: 準備場の表向き（横向きでない）のカードと、その付加カード。
     * 付加カードは 046 の位置で数える。
     *
     * @return list<array{card: Card, host: Card}>
     */
    private function activeCards(int $playerId): array
    {
        $result = [];
        foreach ($this->cards->list(Zone::Prep, $playerId) as $host) {
            if ($host->sideways) {
                continue;
            }
            $result[] = ['card' => $host, 'host' => $host];
            foreach ($this->cards->attachedTo($host) as $attached) {
                $result[] = ['card' => $attached, 'host' => $host];
            }
        }
        return $result;
    }

    /**
     * 探査中の効果（補正と禁止）と、その手番だけの補正（013）を集める（008 §4.2）。
     * 補正の源は準備場にある間だけ有効なので、除外されれば自然に消える（008 N-9 (a)）。
     */
    public function costContext(int $playerId): CostContext
    {
        $mods = [];
        $bans = [];
        $prep = $this->cards->list(Zone::Prep, $playerId);
        foreach ($this->activeCards($playerId) as $entry) {
            $definition = CardDefinitions::get(sprintf('%03d', $entry['card']->cardNo));
            foreach ($definition?->effectsFor(EffectTiming::DuringExplore) ?? [] as $effect) {
                $params = $effect->params;
                if ($effect->kind === EffectKind::CostModifier) {
                    if (!$this->conditionHolds((string) ($params['condition'] ?? ''), $playerId, $entry['host'], $prep)) {
                        continue;
                    }
                    $filter = $params['symbol_filter'] ?? null;
                    $mods[] = [
                        'amount' => (int) $params['amount'],
                        'symbols' => is_array($filter) ? array_map(static fn(string $s): Symbol => Symbol::from($s), $filter) : null,
                    ];
                } elseif ($effect->kind === EffectKind::ExploreBan) {
                    $symbols = $params['symbols'] ?? null;
                    $bans[] = [
                        'scope' => (string) $params['scope'],
                        'symbols' => is_array($symbols) ? array_map(static fn(string $s): Symbol => Symbol::from($s), $symbols) : null,
                        'except_unknown' => ($params['except'] ?? null) === 'UNKNOWN',
                    ];
                }
            }
        }
        $turnMod = $this->globals->exploreState()['cost_mod'] ?? 0;
        if ($turnMod !== 0) {
            $mods[] = ['amount' => $turnMod, 'symbols' => null];
        }

        return new CostContext($mods, $bans);
    }

    /**
     * 補正の条件（008 §4.3）。
     *
     * @param list<Card> $prep 自分の準備場（横向きを含む。order の昇順）
     */
    private function conditionHolds(string $condition, int $playerId, Card $host, array $prep): bool
    {
        $order = $this->globals->exploreOrder();
        switch ($condition) {
            case '':
                return true;
            case 'MIN_OUTPUT': // 015: 全プレイヤーの中で自分の探査出力が最小（同値も最小）
                $mine = $this->players->exploreOutput($playerId) ?? 0;
                foreach ($order as $other) {
                    if (($this->players->exploreOutput($other) ?? 0) < $mine) {
                        return false;
                    }
                }
                return true;
            case 'LAST_IN_ORDER': // 027
                return $order !== [] && $order[count($order) - 1] === $playerId;
            case 'FIRST_IN_ORDER': // 038
                return $order !== [] && $order[0] === $playerId;
            case 'IS_LAST_PREPARED': // 037: 後ろに横向きのカードがあっても最後ではない（008 N-6）
                return $prep !== [] && $prep[count($prep) - 1]->id === $host->id;
            default:
                return false;
        }
    }

    // ---- 状態 42: 探査時の効果（011・013） ----

    /**
     * 手番の開始時に使える探査時の効果（準備場の表向き・横向きでないカード。001 Q-25）。
     * 011 は 1 手番に 1 回、枚数が 1 枚以上の探査山札があるときだけ。
     *
     * @return list<array{cardId: int, cardNo: int, kind: string, deckCosts: list<int>}>
     */
    public function usableOnExplore(int $playerId): array
    {
        $state = $this->globals->exploreState();
        if ($state === null || $state['player_id'] !== $playerId) {
            return [];
        }
        $deckCosts = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            if ($this->cards->count(Zone::ExploreDeck, $cost) > 0) {
                $deckCosts[] = $cost;
            }
        }
        $usable = [];
        foreach ($this->activeCards($playerId) as $entry) {
            $card = $entry['card'];
            $effect = CardDefinitions::get(sprintf('%03d', $card->cardNo))?->effectsFor(EffectTiming::OnExplore)[0] ?? null;
            if ($effect === null || in_array($card->id, $state['used_on_explore'], true)) {
                continue;
            }
            if ($effect->kind === EffectKind::AddPileTopToRow && $deckCosts !== []) {
                $usable[] = ['cardId' => $card->id, 'cardNo' => $card->cardNo, 'kind' => 'radar', 'deckCosts' => $deckCosts];
            } elseif ($effect->kind === EffectKind::CostModifier) {
                $usable[] = ['cardId' => $card->id, 'cardNo' => $card->cardNo, 'kind' => 'map', 'deckCosts' => []];
            }
        }
        return $usable;
    }

    public function hasUsableOnExplore(int $playerId): bool
    {
        return $this->usableOnExplore($playerId) !== [];
    }

    /**
     * 探査時の効果を 1 つ使う（actUseOnExplore）。011 は deckCost の山札の上を列へ、013 は 013 を除外して手番の間 -1。
     * 続けて使えるものが残っていれば again、なければ done。
     *
     * @throws ExploreRuleViolation
     */
    public function useOnExplore(int $playerId, int $cardId, ?int $deckCost): ExploreStepResult
    {
        $found = null;
        foreach ($this->usableOnExplore($playerId) as $usable) {
            if ($usable['cardId'] === $cardId) {
                $found = $usable;
            }
        }
        if ($found === null) {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
        }
        $state = $this->globals->exploreState() ?? throw new ExploreRuleViolation(ExploreRuleViolation::NOT_YOUR_TURN);
        $source = $this->cards->get($cardId);
        $notifications = [];

        if ($found['kind'] === 'radar') {
            if ($deckCost === null || !in_array($deckCost, $found['deckCosts'], true)) {
                throw new ExploreRuleViolation(ExploreRuleViolation::NOT_A_TARGET);
            }
            $top = $this->cards->peekTop(Zone::ExploreDeck, $deckCost, 1)[0];
            $slot = $this->cards->rowFillSlots($deckCost, 1)[0];
            $this->cards->move($top, Zone::Row, $deckCost, $slot);
            $notifications[] = new Notification(
                null,
                'rowCardAdded',
                clienttranslate('${player_name} adds ${card_name} to the ${cost}-cost row (${source_name})'),
                [
                    'player_id' => $playerId,
                    'player_name' => ($this->playerName)($playerId),
                    'cost' => $deckCost,
                    'slot' => $slot,
                    'card' => DrawEffects::cardView($top),
                    'card_name' => DrawEffects::nameOf($top->cardNo),
                    'source_name' => DrawEffects::nameOf($source->cardNo),
                    'i18n' => ['card_name', 'source_name'],
                ],
            );
            $notifications[] = new Notification(null, 'countersUpdate', '', ['piles' => [$deckCost => $this->cards->count(Zone::ExploreDeck, $deckCost)]]);
            // 山札を尽きさせた場合も終了条件（006 未確定事項 No.8）。ラウンドは最後まで行う
            if ($deckCost === 8 && $this->cards->count(Zone::ExploreDeck, 8) === 0) {
                array_push($notifications, ...$this->triggerEnd(GlobalValues::END_PILE8, 0));
            }
            $state['used_on_explore'][] = $cardId;
        } else {
            // 013: 013 を除外する。除外しても探査出力は再計算しない（002 No.33）。046 の付加カードなら 046 ごと除外する
            $host = $this->hostOf($source);
            $notifications[] = $this->removal->remove($playerId, $host, DrawEffects::nameOf($source->cardNo));
            $notifications[] = new Notification(null, 'countersUpdate', '', ['players' => [$playerId => [
                'prepCount' => $this->cards->count(Zone::Prep, $playerId),
            ]]]);
            $state['cost_mod'] -= 1;
            $state['used_on_explore'][] = $cardId;
        }
        $this->globals->setExploreState($state);

        return new ExploreStepResult($this->hasUsableOnExplore($playerId) ? 'again' : 'done', $notifications);
    }

    private function hostOf(Card $card): Card
    {
        if ($card->zone === Zone::Attached) {
            return $this->cards->get($card->locationArg);
        }
        return $card;
    }

    // ---- 012: 追加探査 ----

    /**
     * 状態 44 の末尾。012 が有効で、追加で探査できる対象があれば additional（43 を再入場）、なければ done（48 へ）。
     * 追加探査は 1 回だけ（012 は 1 枚）。判定は 44 の時点の準備場で再評価する（008 N-9）。
     */
    public function afterAcquisitions(int $playerId): string
    {
        $state = $this->globals->exploreState();
        if ($state === null || $state['player_id'] !== $playerId || $state['explored'] < 1 || $state['additional_done']) {
            return 'done';
        }
        $has012 = false;
        foreach ($this->activeCards($playerId) as $entry) {
            foreach (CardDefinitions::get(sprintf('%03d', $entry['card']->cardNo))?->effectsFor(EffectTiming::DuringExplore) ?? [] as $effect) {
                if ($effect->kind === EffectKind::AdditionalExplore) {
                    $has012 = true;
                }
            }
        }
        if (!$has012 || ExploreRules::isEmpty($this->targets($playerId))) {
            return 'done';
        }
        $state['additional_done'] = true;
        $this->globals->setExploreState($state);

        return 'additional';
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
     * 獲得時効果は解決待ちに積むだけ（状態 44 が解決する）。未確認帯域の獲得とコスト 8 の山札の枯渇では終了フラグを立てる。
     *
     * @throws ExploreRuleViolation
     */
    public function explore(int $playerId, string $source, int $cardId, int $cost): ExploreStepResult
    {
        $state = $this->globals->exploreState();
        if ($state === null || $state['player_id'] !== $playerId) {
            throw new ExploreRuleViolation(ExploreRuleViolation::NOT_YOUR_TURN);
        }
        // 追加探査（012）は 44 が additional_done を立てて再入場したときだけ、あと 1 枚
        $limit = $state['additional_done'] ? 2 : 1;
        if ($state['explored'] >= $limit) {
            throw new ExploreRuleViolation(ExploreRuleViolation::ALREADY_EXPLORED);
        }
        $additional = $state['additional_done'];

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
                'additional' => $additional,
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

        // 獲得時効果は状態 44 が解決する
        $this->acquire->enqueue($card->id, $playerId);

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
