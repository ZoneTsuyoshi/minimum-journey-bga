<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\CardDefinitions;

/**
 * 探査出力の計算に渡す、準備場の表向きのカード 1 枚（準備した順。裏向きのカードは含めない）。
 */
final class PrepCardInput
{
    /**
     * @param int $attachedCardNo 046 に付加されているカードの番号（011 No.3・No.4 の暫定案: 探査力・名声・効果・シンボルを加える）
     */
    public function __construct(
        public readonly int $cardNo,
        public readonly int $printedPower,
        public readonly bool $sideways,
        public readonly ?int $attachedCardNo = null,
    ) {
    }

    /**
     * カード定義の印字の探査力から作る（テスト・計算用）。
     */
    public static function of(int $cardNo, bool $sideways = false, ?int $attachedCardNo = null): self
    {
        $definition = CardDefinitions::get(sprintf('%03d', $cardNo));
        return new self($cardNo, $definition->power ?? 0, $sideways, $attachedCardNo);
    }

    /**
     * 探査力変動の前の探査力。横向きは探査力もカード効果も無視する（001 §6-1）。
     */
    public function basePower(): int
    {
        return $this->sideways ? 0 : $this->printedPower;
    }
}
