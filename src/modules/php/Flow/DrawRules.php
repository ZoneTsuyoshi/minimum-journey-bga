<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * ドロー時効果の解決順に関する純粋な規則（010 §5・§6.2、008 §1.5）。BGA にも DB にも依存しない。
 */
final class DrawRules
{
    private function __construct()
    {
    }

    /**
     * 抑止カード 1 枚の条件を満たすか（008 §1.5 の passes）。
     * 039 は ALLOW_SYMBOL:VIRGO_CLUSTER（引いた時点のシンボルに含むものだけ通す。J4）、
     * 053 は ALLOW_SAME_OWNER（持ち主が抑止カードの持ち主と同じものだけ通す）。
     *
     * @param list<Symbol> $symbols 引いた時点のカードのシンボル（付加カードのシンボルを足す前）
     */
    public static function passes(string $rule, array $symbols, int $owner, int $filterOwner): bool
    {
        if ($rule === 'ALLOW_SAME_OWNER') {
            return $owner === $filterOwner;
        }
        if (str_starts_with($rule, 'ALLOW_SYMBOL:')) {
            $symbol = Symbol::tryFrom(substr($rule, strlen('ALLOW_SYMBOL:')));
            return $symbol !== null && in_array($symbol, $symbols, true);
        }
        throw new \InvalidArgumentException("unknown suppress rule: $rule");
    }

    /**
     * 同優先度を順番に処理するときの並び（D4。お守りから遠いプレイヤーから反時計回りで、所持者が最後）。
     * お守りの所持者からの時計回り（席順 D10）の距離の大きい順。探査手番（所持者が最優先）とは逆向きで、関数を共用しない。
     *
     * @param list<int> $seatOrder 席順（時計回り）のプレイヤー ID
     * @return list<int>
     */
    public static function farthestFirst(array $seatOrder, ?int $amuletHolder): array
    {
        $count = count($seatOrder);
        $base = $amuletHolder === null ? false : array_search($amuletHolder, $seatOrder, true);
        if ($count === 0 || $base === false) {
            return $seatOrder;
        }
        $distances = [];
        foreach ($seatOrder as $index => $playerId) {
            $distances[$playerId] = ($index - $base + $count) % $count;
        }
        $players = $seatOrder;
        usort($players, static fn(int $a, int $b): int => $distances[$b] <=> $distances[$a]);
        return $players;
    }
}
