<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 帰還フェイズ①のお守りの新所有者（001 §7-1）の純粋な判定。
 * 探査出力が最小のプレイヤー。同点は探査手番が遅い方。
 */
final class CharmTransfer
{
    private function __construct()
    {
    }

    /**
     * @param array<int, int> $outputs プレイヤー ID => 探査手番決定に使った最終の探査出力（Q-20）
     * @param list<int> $exploreOrder 探査手番の順（先頭が最初）。探査出力を持つ全員を含む
     * @return int|null 新しい所有者。プレイヤーがいなければ null
     */
    public static function newHolder(array $outputs, array $exploreOrder): ?int
    {
        $best = null;
        $bestOutput = 0;
        foreach ($exploreOrder as $playerId) {
            $output = $outputs[$playerId] ?? 0;
            // 出力が小さい方、同点なら手番が遅い方（添字が大きい方）。手番は先頭から見るので <= で上書きする
            if ($best === null || $output <= $bestOutput) {
                $best = $playerId;
                $bestOutput = $output;
            }
        }
        return $best;
    }
}
