<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査手番の決定（001 §6-2）の純粋な部分。
 * 準備枚数（表向き＋横向き。裏向きとお守りは数えない）が少ない順 → 探査出力が小さい順 → お守り所持者から時計回りで近い順。
 */
final class ExploreOrder
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $seatOrder 席順（時計回り）のプレイヤー ID
     * @param array<int, int> $prepCounts プレイヤー ID => 準備枚数
     * @param array<int, int> $outputs プレイヤー ID => 探査出力（お守り加算・半減後）
     * @param int|null $charmHolder お守りの所持者
     * @return list<int> 探査手番の順（先頭が最初）
     */
    public static function decide(array $seatOrder, array $prepCounts, array $outputs, ?int $charmHolder): array
    {
        $rows = [];
        foreach ($seatOrder as $index => $playerId) {
            $rows[] = [
                $prepCounts[$playerId] ?? 0,
                $outputs[$playerId] ?? 0,
                self::distance($seatOrder, $charmHolder, $index),
                $playerId,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_map(static fn(array $row): int => $row[3], $rows);
    }

    /**
     * お守り所持者からの時計回りの距離（所持者 0、次の席 1、...）。所持者がいなければ席順の添字。
     *
     * @param list<int> $seatOrder
     */
    private static function distance(array $seatOrder, ?int $charmHolder, int $index): int
    {
        $holderIndex = $charmHolder === null ? false : array_search($charmHolder, $seatOrder, true);
        if ($holderIndex === false) {
            return $index;
        }
        $count = count($seatOrder);
        return ($index - $holderIndex + $count) % $count;
    }
}
