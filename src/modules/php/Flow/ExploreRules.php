<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査できる対象の判定（001 §6-3、006 §6.1）の純粋な部分。探査コスト ≤ 探査出力のものだけを返す。
 *
 * @phpstan-type RowTarget array{cardId: int, slot: int, cost: int, effectiveCost: int}
 * @phpstan-type UnknownTarget array{cardId: int, effectiveCost: int}
 * @phpstan-type DeckTarget array{cost: int, effectiveCost: int, count: int}
 * @phpstan-type Targets array{rowTargets: list<RowTarget>, unknownTarget: UnknownTarget|null, deckTargets: list<DeckTarget>}
 */
final class ExploreRules
{
    /** 未確認帯域の探査コスト（001 §2） */
    public const UNKNOWN_COST = 10;

    private function __construct()
    {
    }

    /**
     * 実効コスト。探査コストの増減（探査時・探査中の効果。I-11）の差し込み口で、いまは印字のコストのまま。
     */
    public static function effectiveCost(int $cost): int
    {
        return $cost;
    }

    /**
     * @param int $output 探査出力
     * @param list<array{cardId: int, slot: int, cost: int}> $rowCards 列の表向きのカード（cost は属する列のコスト）
     * @param int|null $unknownCardId 探査場にある未確認帯域のカード ID（なければ null）
     * @param array<int, int> $deckCounts 探査山札のコスト => 枚数
     * @return Targets
     */
    public static function targets(int $output, array $rowCards, ?int $unknownCardId, array $deckCounts): array
    {
        $rowTargets = [];
        foreach ($rowCards as $card) {
            $effective = self::effectiveCost($card['cost']);
            if ($effective <= $output) {
                $rowTargets[] = $card + ['effectiveCost' => $effective];
            }
        }

        $unknownTarget = null;
        if ($unknownCardId !== null) {
            $effective = self::effectiveCost(self::UNKNOWN_COST);
            if ($effective <= $output) {
                $unknownTarget = ['cardId' => $unknownCardId, 'effectiveCost' => $effective];
            }
        }

        // 山札の上は、必要な出力が山札のコスト（列のカードのコストと同じ）。列が空でも、列に探査できるカードがあっても探査できる
        $deckTargets = [];
        ksort($deckCounts);
        foreach ($deckCounts as $cost => $count) {
            $effective = self::effectiveCost($cost);
            if ($count > 0 && $effective <= $output) {
                $deckTargets[] = ['cost' => $cost, 'effectiveCost' => $effective, 'count' => $count];
            }
        }

        return ['rowTargets' => $rowTargets, 'unknownTarget' => $unknownTarget, 'deckTargets' => $deckTargets];
    }

    /**
     * 探査できる対象が 1 つもないか（自動パスの条件。009 §9.2）。
     *
     * @param Targets $targets
     */
    public static function isEmpty(array $targets): bool
    {
        return $targets['rowTargets'] === [] && $targets['unknownTarget'] === null && $targets['deckTargets'] === [];
    }
}
