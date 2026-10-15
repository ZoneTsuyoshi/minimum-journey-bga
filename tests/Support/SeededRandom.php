<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Support;

/**
 * 種を指定できる乱数。bga_rand の代わりに渡す（両端を含む）。結果は種ごとに再現できる。
 */
final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed * 2654435761 + 12345) & 0x7fffffff;
    }

    public function __invoke(int $min, int $max): int
    {
        // 線形合同法（上位ビットを使う）
        $this->state = ($this->state * 1103515245 + 12345) & 0x7fffffff;
        return $min + ($this->state >> 8) % ($max - $min + 1);
    }
}
