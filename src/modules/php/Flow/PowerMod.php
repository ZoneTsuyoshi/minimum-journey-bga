<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査力変動（011 の段階エンジン）の差し込み口。
 * I-06 では何もしない（印字の探査力をそのまま返す）。I-10 で 011 §6-1 の S1〜S4 を実装して置き換える。
 */
final class PowerMod
{
    private function __construct()
    {
    }

    /**
     * 準備場のカードごとの探査力（探査力変動を反映した値）。
     *
     * @param list<PrepCardInput> $cards 準備した順
     * @param int $round ラウンド数
     * @return list<int> $cards と同じ並びの探査力
     */
    public static function apply(array $cards, int $round): array
    {
        unset($round); // I-10 で使う
        return array_map(static fn(PrepCardInput $c): int => $c->basePower(), $cards);
    }
}
