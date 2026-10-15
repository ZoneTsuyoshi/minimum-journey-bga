<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * DB に触れない純粋な計算（SQL 断片の組み立て、順序の計算、シャッフル）。
 * 山の「上」は card_order が大きい側（ItemManager の規約と同じ。仕様書 007 V2）。
 */
final class DeckMath
{
    /**
     * 置き場所の WHERE 句。$arg が null なら種類だけで絞る。
     */
    public static function where(Zone $zone, ?int $arg = null): string
    {
        $sql = "`card_location` = '" . $zone->value . "'";
        if ($arg !== null) {
            $sql .= ' AND `card_location_arg` = ' . $arg;
        }
        return $sql;
    }

    /**
     * 山の一番上に置くときの order。$currentMax は山の現在の最大値（空なら null）。
     */
    public static function topOrder(?int $currentMax): int
    {
        return ($currentMax ?? 0) + 1;
    }

    /**
     * 山の一番下に置くときの order（符号付きなので負になりうる）。$currentMin は現在の最小値（空なら null）。
     */
    public static function bottomOrder(?int $currentMin): int
    {
        return ($currentMin ?? 0) - 1;
    }

    /**
     * 空きスロット番号（0 始まり）の小さい順に $count 個を返す。
     *
     * @param list<int> $occupied 埋まっているスロット番号
     * @return list<int>
     */
    public static function freeSlots(array $occupied, int $count): array
    {
        $taken = array_flip($occupied);
        $result = [];
        for ($slot = 0; count($result) < $count; $slot++) {
            if (!isset($taken[$slot])) {
                $result[] = $slot;
            }
        }
        return $result;
    }

    /**
     * Fisher-Yates シャッフル（仕様書 007 §5.2）。乱数は $rand(min, max)（両端を含む）で渡す。
     *
     * @template T
     * @param list<T> $list
     * @param callable(int, int): int $rand 本番は bga_rand
     * @return list<T>
     */
    public static function shuffleList(array $list, callable $rand): array
    {
        for ($i = count($list) - 1; $i >= 1; $i--) {
            $j = $rand(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
        return $list;
    }
}
