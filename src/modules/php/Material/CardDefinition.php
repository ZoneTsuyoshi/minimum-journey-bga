<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * 1 種類のカードの静的な定義（002 §2、008 §1.2）。
 */
final readonly class CardDefinition
{
    /**
     * @param string $cardNo 3 桁のカード番号（'001'〜'058'）
     * @param string $id 英語識別子（snake_case）
     * @param list<Symbol> $symbols
     * @param int|null $cost 探査コスト。お守りは null
     * @param int $power 探査力の印刷値
     * @param int $fame 名声の印刷値
     * @param int $count そのカード種別の枚数
     * @param int $artCount 絵柄の数（card_art は 1〜artCount）
     * @param list<EffectDefinition> $effects
     */
    public function __construct(
        public string $cardNo,
        public string $id,
        public string $nameEn,
        public string $nameJa,
        public CardCategory $category,
        public array $symbols,
        public ?int $cost,
        public int $power,
        public int $fame,
        public int $count,
        public int $artCount,
        public array $effects = [],
    ) {
    }

    /**
     * 効果タイミング（002 の「効果タイミング」欄。効果記述子の並び順）。
     *
     * @return list<EffectTiming>
     */
    public function timings(): array
    {
        return array_map(static fn(EffectDefinition $e): EffectTiming => $e->timing, $this->effects);
    }

    public function hasSymbol(Symbol $symbol): bool
    {
        return in_array($symbol, $this->symbols, true);
    }

    /**
     * 指定タイミングの効果記述子を返す。
     *
     * @return list<EffectDefinition>
     */
    public function effectsFor(EffectTiming $timing): array
    {
        return array_values(array_filter(
            $this->effects,
            static fn(EffectDefinition $e): bool => $e->timing === $timing,
        ));
    }
}
