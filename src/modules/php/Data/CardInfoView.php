<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

use Bga\Games\MinimumJourney\Material\CardDefinition;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\CardTexts;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * ツールチップ用のカード情報（009 §6）。全 58 種の静的な内容で、非公開情報を含まない。
 * 英語の名前と効果テキストは、渡された翻訳関数（Game の _()）で翻訳して返す。
 */
final class CardInfoView
{
    /**
     * @param callable(string): string $translate
     * @return array<int, array{
     *     cardNo: int, name: string, nameEn: string, nameJa: string, cost: int|null, power: int, fame: int,
     *     symbols: list<string>, effects: list<array{timings: list<string>, text: string}>
     * }> カード番号 => 内容
     */
    public static function build(callable $translate): array
    {
        $result = [];
        foreach (CardDefinitions::all() as $cardNo => $definition) {
            $result[(int) $cardNo] = self::one($definition, $translate);
        }
        return $result;
    }

    /**
     * @param callable(string): string $translate
     * @return array{
     *     cardNo: int, name: string, nameEn: string, nameJa: string, cost: int|null, power: int, fame: int,
     *     symbols: list<string>, effects: list<array{timings: list<string>, text: string}>
     * }
     */
    private static function one(CardDefinition $definition, callable $translate): array
    {
        $texts = CardTexts::get($definition->cardNo) ?? ['name' => $definition->nameEn, 'effects' => []];
        $effects = [];
        foreach ($texts['effects'] as $effect) {
            $effects[] = [
                'timings' => array_map(static fn(EffectTiming $t): string => $t->value, $effect['timings']),
                'text' => $translate($effect['text']),
            ];
        }

        return [
            'cardNo' => (int) $definition->cardNo,
            'name' => $translate($texts['name']),
            'nameEn' => $definition->nameEn,
            'nameJa' => $definition->nameJa,
            'cost' => $definition->cost,
            'power' => $definition->power,
            'fame' => $definition->fame,
            'symbols' => array_map(static fn(Symbol $s): string => $s->value, $definition->symbols),
            'effects' => $effects,
        ];
    }
}
