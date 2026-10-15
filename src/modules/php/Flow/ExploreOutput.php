<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査出力の計算（011 §6、001 §6-1）の純粋な部分。合計 → お守り → 半減 → 下限 0 の順。
 */
final class ExploreOutput
{
    private function __construct()
    {
    }

    /**
     * お守りの探査力。印字の探査力で、zero_on_round のラウンドだけ 0（航路安全は 1 ラウンド目が 0。001 E21）。
     */
    public static function charmPower(int $printedPower, ?int $zeroOnRound, int $round): int
    {
        return $zeroOnRound !== null && $round === $zeroOnRound ? 0 : $printedPower;
    }

    /**
     * 準備中の現在の探査出力: 合計 + お守り。半減は含めない（009 §8.3、No.18）。下限 0。
     */
    public static function now(int $total, int $charmPower): int
    {
        return max(0, $total + $charmPower);
    }

    /**
     * 確定する探査出力: 合計 + お守りを、半減する人は半分（端数切り捨て）にする。下限 0。
     */
    public static function decided(int $total, int $charmPower, bool $halved): int
    {
        $sum = $total + $charmPower;
        return max(0, $halved ? intdiv($sum, 2) : $sum);
    }
}
