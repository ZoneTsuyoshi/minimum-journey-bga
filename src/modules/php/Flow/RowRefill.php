<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 帰還フェイズ③の列の補充枚数（001 §7-3）の純粋な計算。
 */
final class RowRefill
{
    private function __construct()
    {
    }

    /**
     * 補充する枚数。規定枚数までで、探査山札が足りなければあるだけ。規定を超えている列（011 の追加）は補充しない。
     *
     * @param int $rowSize 列の規定枚数（人数とコストで決まる）
     * @param int $rowCount 列の表向きの枚数
     * @param int $deckCount 探査山札の残り枚数
     */
    public static function count(int $rowSize, int $rowCount, int $deckCount): int
    {
        return max(0, min($rowSize - $rowCount, $deckCount));
    }
}
