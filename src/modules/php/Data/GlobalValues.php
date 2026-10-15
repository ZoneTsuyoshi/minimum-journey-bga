<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

use Bga\GameFramework\Db\Globals;

/**
 * ゲーム全体の値（$this->bga->globals。仕様書 007 §4.2）のアクセサ。
 * キー名と初期値は 007 §4.2 の表に合わせる。値が未設定のときは初期値を返す。
 *
 * @phpstan-type NextChoiceRecords array{force_draw: bool, draw_only: list<int>, stop_only: list<int>, late: int|null}
 * @phpstan-type ExploreState array{player_id: int, sum_cost: int, explored: int, used_on_explore: list<int>,
 *     additional_done: bool, cost_mod: int}
 * @phpstan-type LockedCard array{player_id: int, card_id: int}
 */
final class GlobalValues
{
    public const PHASE_PREP = 'prep';
    public const PHASE_EXPLORE = 'explore';
    public const PHASE_RETURN = 'return';

    public const END_UNKNOWN = 'unknown';
    public const END_PILE8 = 'pile8';

    public function __construct(private readonly Globals $globals)
    {
    }

    /**
     * 007 §4.2 の初期値を書く（setupNewGame 用）。pile8_initial などの開始時の基準値は別に set する。
     */
    public function initialize(): void
    {
        $this->setRoundNo(0);
        $this->setPhase(self::PHASE_PREP);
        $this->setPrepSelectors([]);
        $this->setPrepLatePlayer(null);
        $this->setPrepReasons([]);
        $this->setNextChoiceRecords(self::emptyNextChoiceRecords());
        $this->setForceDrawTmp(false);
        $this->setResolveLevel(ResolveLevel::Init);
        $this->setExploreOrder([]);
        $this->setExploreIndex(0);
        $this->setExploreState(null);
        $this->setLockedCard(null);
        $this->setGameEndTriggered(false);
        $this->setEndReason(null);
        $this->setUnknownAcquirer(null);
    }

    /**
     * @return NextChoiceRecords
     */
    public static function emptyNextChoiceRecords(): array
    {
        return ['force_draw' => false, 'draw_only' => [], 'stop_only' => [], 'late' => null];
    }

    public function roundNo(): int
    {
        return (int) $this->globals->get('round_no', 0);
    }

    public function setRoundNo(int $value): void
    {
        $this->globals->set('round_no', $value);
    }

    public function incRoundNo(): int
    {
        return $this->globals->inc('round_no', 1);
    }

    public function phase(): string
    {
        return (string) $this->globals->get('phase', self::PHASE_PREP);
    }

    public function setPhase(string $phase): void
    {
        if (!in_array($phase, [self::PHASE_PREP, self::PHASE_EXPLORE, self::PHASE_RETURN], true)) {
            throw new \InvalidArgumentException("invalid phase: $phase");
        }
        $this->globals->set('phase', $phase);
    }

    /**
     * @return list<int>
     */
    public function prepSelectors(): array
    {
        return $this->intList('prep_selectors');
    }

    /**
     * @param list<int> $value
     */
    public function setPrepSelectors(array $value): void
    {
        $this->globals->set('prep_selectors', $value);
    }

    public function prepLatePlayer(): ?int
    {
        return $this->nullableInt('prep_late_player');
    }

    public function setPrepLatePlayer(?int $playerId): void
    {
        $this->globals->set('prep_late_player', $playerId);
    }

    /**
     * この回に選択が確定したプレイヤーの理由（memoryAlbum／safetyDevice／bigBang／emptyDeck）。
     * 007 §4.2 に無い内部値。N2 `choiceRevealed` の reason と、21 の args `forced` を作るために 20 が書く。
     *
     * @return array<int, string> プレイヤー ID => 理由
     */
    public function prepReasons(): array
    {
        $v = $this->globals->get('prep_reasons', []);
        $result = [];
        foreach (is_array($v) ? $v : [] as $playerId => $reason) {
            $result[(int) $playerId] = (string) $reason;
        }
        return $result;
    }

    /**
     * @param array<int, string> $value
     */
    public function setPrepReasons(array $value): void
    {
        $this->globals->set('prep_reasons', $value);
    }

    /**
     * @return NextChoiceRecords
     */
    public function nextChoiceRecords(): array
    {
        $v = $this->globals->get('next_choice_records');
        if (!is_array($v)) {
            return self::emptyNextChoiceRecords();
        }
        return [
            'force_draw' => (bool) ($v['force_draw'] ?? false),
            'draw_only' => array_map('intval', array_values((array) ($v['draw_only'] ?? []))),
            'stop_only' => array_map('intval', array_values((array) ($v['stop_only'] ?? []))),
            'late' => isset($v['late']) ? (int) $v['late'] : null,
        ];
    }

    /**
     * @param NextChoiceRecords $value
     */
    public function setNextChoiceRecords(array $value): void
    {
        $this->globals->set('next_choice_records', $value);
    }

    public function forceDrawTmp(): bool
    {
        return (bool) $this->globals->get('force_draw_tmp', false);
    }

    public function setForceDrawTmp(bool $value): void
    {
        $this->globals->set('force_draw_tmp', $value);
    }

    public function resolveLevel(): ResolveLevel
    {
        return ResolveLevel::tryFrom((string) $this->globals->get('resolve_level', '')) ?? ResolveLevel::Init;
    }

    public function setResolveLevel(ResolveLevel $level): void
    {
        $this->globals->set('resolve_level', $level->value);
    }

    /**
     * @return list<int>
     */
    public function exploreOrder(): array
    {
        return $this->intList('explore_order');
    }

    /**
     * @param list<int> $value
     */
    public function setExploreOrder(array $value): void
    {
        $this->globals->set('explore_order', $value);
    }

    public function exploreIndex(): int
    {
        return (int) $this->globals->get('explore_index', 0);
    }

    public function setExploreIndex(int $value): void
    {
        $this->globals->set('explore_index', $value);
    }

    /**
     * @return ExploreState|null
     */
    public function exploreState(): ?array
    {
        $v = $this->globals->get('explore_state');
        if (!is_array($v)) {
            return null;
        }
        return [
            'player_id' => (int) ($v['player_id'] ?? 0),
            'sum_cost' => (int) ($v['sum_cost'] ?? 0),
            'explored' => (int) ($v['explored'] ?? 0),
            'used_on_explore' => array_map('intval', array_values((array) ($v['used_on_explore'] ?? []))),
            'additional_done' => (bool) ($v['additional_done'] ?? false),
            'cost_mod' => (int) ($v['cost_mod'] ?? 0),
        ];
    }

    /**
     * @param ExploreState|null $value
     */
    public function setExploreState(?array $value): void
    {
        $this->globals->set('explore_state', $value);
    }

    /**
     * @return LockedCard|null
     */
    public function lockedCard(): ?array
    {
        $v = $this->globals->get('locked_card');
        if (!is_array($v)) {
            return null;
        }
        return ['player_id' => (int) ($v['player_id'] ?? 0), 'card_id' => (int) ($v['card_id'] ?? 0)];
    }

    /**
     * @param LockedCard|null $value
     */
    public function setLockedCard(?array $value): void
    {
        $this->globals->set('locked_card', $value);
    }

    public function gameEndTriggered(): bool
    {
        return (bool) $this->globals->get('game_end_triggered', false);
    }

    public function setGameEndTriggered(bool $value): void
    {
        $this->globals->set('game_end_triggered', $value);
    }

    public function endReason(): ?string
    {
        $v = $this->globals->get('end_reason');
        return $v === null ? null : (string) $v;
    }

    public function setEndReason(?string $reason): void
    {
        if ($reason !== null && !in_array($reason, [self::END_UNKNOWN, self::END_PILE8], true)) {
            throw new \InvalidArgumentException("invalid end_reason: $reason");
        }
        $this->globals->set('end_reason', $reason);
    }

    public function unknownAcquirer(): ?int
    {
        return $this->nullableInt('unknown_acquirer');
    }

    public function setUnknownAcquirer(?int $playerId): void
    {
        $this->globals->set('unknown_acquirer', $playerId);
    }

    public function pile8Initial(): int
    {
        return (int) $this->globals->get('pile8_initial', 0);
    }

    public function setPile8Initial(int $value): void
    {
        $this->globals->set('pile8_initial', $value);
    }

    public function pilesInitialTotal(): int
    {
        return (int) $this->globals->get('piles_initial_total', 0);
    }

    public function setPilesInitialTotal(int $value): void
    {
        $this->globals->set('piles_initial_total', $value);
    }

    /**
     * @return list<int>
     */
    private function intList(string $name): array
    {
        $v = $this->globals->get($name, []);
        return is_array($v) ? array_map('intval', array_values($v)) : [];
    }

    private function nullableInt(string $name): ?int
    {
        $v = $this->globals->get($name);
        return $v === null ? null : (int) $v;
    }
}
