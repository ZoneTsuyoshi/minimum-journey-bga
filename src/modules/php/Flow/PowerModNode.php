<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\EffectDefinition;
use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * 探査力変動の計算中の、準備場のカード 1 枚の作業用の状態（011 §6-1 の c）。PowerMod の内部でだけ使う。
 *
 * @internal
 */
final class PowerModNode
{
    /**
     * @param list<string> $cardNos 自身と付加カードの 3 桁のカード番号
     * @param list<Symbol> $symbols 自身と付加カードのシンボル
     * @param list<EffectDefinition> $effects 自身と付加カードの【探査力変動】効果（段階つきのもの）
     */
    public function __construct(
        public readonly PrepCardInput $input,
        public readonly array $cardNos,
        public readonly array $symbols,
        public readonly array $effects,
        public readonly int $fame,
        public readonly bool $hasFameMod,
        public int $power,
        public bool $active,
    ) {
    }

    public function hasSymbol(Symbol $symbol): bool
    {
        return in_array($symbol, $this->symbols, true);
    }
}
