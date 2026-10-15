<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\Symbol;

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
     * @param int $output 探査出力
     * @param list<array{cardId: int, slot: int, cost: int, symbols?: list<Symbol>}> $rowCards 列の表向きのカード（cost は属する列のコスト）
     * @param int|null $unknownCardId 探査場にある未確認帯域のカード ID（なければ null）
     * @param array<int, int> $deckCounts 探査山札のコスト => 枚数
     * @param CostContext|null $context 探査中効果の補正と禁止（なければ印字のコストのまま）
     * @param int $sumCost これまでに探査したカードの実効コストの合計（012 の追加探査。最初の探査は 0）
     * @return Targets
     */
    public static function targets(
        int $output,
        array $rowCards,
        ?int $unknownCardId,
        array $deckCounts,
        ?CostContext $context = null,
        int $sumCost = 0,
    ): array {
        $context ??= new CostContext();
        $rowTargets = [];
        foreach ($rowCards as $card) {
            $symbols = $card['symbols'] ?? [CostContext::pileSymbol($card['cost'])];
            if ($context->rowBanned($symbols)) {
                continue;
            }
            $effective = $context->effective($card['cost'], $symbols);
            if ($sumCost + $effective <= $output) {
                $rowTargets[] = ['cardId' => $card['cardId'], 'slot' => $card['slot'], 'cost' => $card['cost'], 'effectiveCost' => $effective];
            }
        }

        $unknownTarget = null;
        if ($unknownCardId !== null && !$context->unknownBanned()) {
            $effective = $context->effective(self::UNKNOWN_COST, [Symbol::Unknown]);
            if ($sumCost + $effective <= $output) {
                $unknownTarget = ['cardId' => $unknownCardId, 'effectiveCost' => $effective];
            }
        }

        // 山札の上は、必要な出力が山札のコスト（列のカードのコストと同じ）。025・026 の禁止は及ばない（FAQ 7）
        $deckTargets = [];
        ksort($deckCounts);
        foreach ($deckCounts as $cost => $count) {
            $effective = $context->effective($cost, [CostContext::pileSymbol($cost)]);
            if ($count > 0 && $sumCost + $effective <= $output) {
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
