<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 帰還フェイズ③の補充の前に行う、探査場の列の左詰め（作者の指示 2026-11-18）の純粋な計算。
 * カードの並び順を保ったまま、スロット番号を 0 から振り直す。
 */
final class RowCompact
{
    private function __construct()
    {
    }

    /**
     * 動くカードだけを返す（すでに正しい位置のカードは含めない）。
     *
     * @param array<int, int> $slots カード ID => 現在のスロット番号
     * @return array<int, int> カード ID => 新しいスロット番号（スロットの昇順）
     */
    public static function moves(array $slots): array
    {
        $ids = array_keys($slots);
        usort($ids, static fn(int $a, int $b): int => [$slots[$a], $a] <=> [$slots[$b], $b]);
        $moves = [];
        foreach ($ids as $new => $id) {
            if ($slots[$id] !== $new) {
                $moves[$id] = $new;
            }
        }
        return $moves;
    }
}
