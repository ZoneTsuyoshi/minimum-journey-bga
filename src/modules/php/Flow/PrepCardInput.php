<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査出力の計算に渡す、準備場の表向きのカード 1 枚（準備した順。裏向きのカードは含めない）。
 */
final class PrepCardInput
{
    public function __construct(
        public readonly int $cardNo,
        public readonly int $printedPower,
        public readonly bool $sideways,
    ) {
    }

    /**
     * 探査力変動の前の探査力。横向きは探査力もカード効果も無視する（001 §6-1）。
     */
    public function basePower(): int
    {
        return $this->sideways ? 0 : $this->printedPower;
    }
}
