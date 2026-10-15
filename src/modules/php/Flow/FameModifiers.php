<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * 名声変動の効果（008 §3。032・033・041・042・045・047）の合計。
 * 全て独立の加算で、評価に順序の依存は無い。デッキにそのカードがあるときだけ加算する。
 * お守りはデッキではなく、シンボルは [お守り] だけなので、数えるシンボルには該当しない（002 No.19）。
 */
final class FameModifiers
{
    /** 数える対象の 5 シンボル（045 の種類数。お守りと [未確認帯域] は含まない） */
    private const KINDS = [Symbol::Earth, Symbol::SolarSystem, Symbol::MilkyWay, Symbol::VirgoCluster, Symbol::Laniakea];

    private function __construct()
    {
    }

    /**
     * @param list<int> $deckCardNos デッキ（山札・準備場・獲得・裏向き。除外を除く）のカード番号。重複あり
     * @param bool $isCharmHolder 帰還フェイズ①の移動後のお守りの所持者か（041 が使う）
     */
    public static function total(array $deckCardNos, bool $isCharmHolder): int
    {
        $has = array_flip($deckCardNos);
        $total = 0;

        if (isset($has[32])) {
            $total += self::countWith($deckCardNos, Symbol::Earth);
        }
        if (isset($has[33])) {
            $total += self::countWith($deckCardNos, Symbol::SolarSystem);
        }
        if (isset($has[41]) && $isCharmHolder) {
            $total += 1;
        }
        if (isset($has[42])) {
            $total += min(self::countWith($deckCardNos, Symbol::SolarSystem), self::countWith($deckCardNos, Symbol::MilkyWay));
        }
        if (isset($has[45])) {
            $kinds = 0;
            foreach (self::KINDS as $symbol) {
                if (self::countWith($deckCardNos, $symbol) > 0) {
                    $kinds++;
                }
            }
            $total += $kinds;
        }
        if (isset($has[47]) && isset($has[56])) {
            $total += 3;
        }

        return $total;
    }

    /**
     * 指定のシンボルを（複数のうち 1 つでも）持つカードの枚数。
     *
     * @param list<int> $cardNos
     */
    private static function countWith(array $cardNos, Symbol $symbol): int
    {
        $count = 0;
        foreach ($cardNos as $cardNo) {
            $definition = CardDefinitions::get(sprintf('%03d', $cardNo));
            if ($definition !== null && $definition->hasSymbol($symbol)) {
                $count++;
            }
        }
        return $count;
    }
}
