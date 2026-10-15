<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 1 人分の最終得点の内訳（001 §9、007 N35）。不変。
 */
final class FinalScore
{
    /**
     * @param int $base デッキのカードの印字の名声の合計
     * @param int $modifier 名声変動（I-11 まで 0）
     * @param int $charmBonus お守りの所持者なら 1
     * @param bool $unknownAcquired 未確認帯域を獲得したか（同点処理の 1 番目）
     * @param array{laniakea: int, virgo: int, milkyWay: int, solar: int} $symbols 同点処理に使う 4 シンボルのカード枚数
     * @param int $aux playerScoreAux に保存する同点処理の値（007 §11.2）
     */
    public function __construct(
        public readonly int $base,
        public readonly int $modifier,
        public readonly int $charmBonus,
        public readonly bool $unknownAcquired,
        public readonly array $symbols,
        public readonly int $aux,
    ) {
    }

    /**
     * 総名声。
     */
    public function fame(): int
    {
        return $this->base + $this->modifier + $this->charmBonus;
    }
}
