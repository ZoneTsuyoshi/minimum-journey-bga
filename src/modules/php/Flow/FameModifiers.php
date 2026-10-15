<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 名声変動の効果（008 §3。032・033・041・042・045・047）の合計の差し込み口。
 * I-07 では 0 を返す。I-11 で、デッキのカード番号とお守りの所持者から各効果の加算を計算する。
 */
final class FameModifiers
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $deckCardNos デッキ（山札・準備場・獲得・裏向き。除外を除く）のカード番号。重複あり
     * @param bool $isCharmHolder 帰還フェイズ①の移動後のお守りの所持者か（041 が使う）
     */
    public static function total(array $deckCardNos, bool $isCharmHolder): int
    {
        return 0;
    }
}
