<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Data\CardInfoView;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\CardTexts;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use PHPUnit\Framework\TestCase;

/**
 * 英語の名前と効果テキスト（009 §6）が、カード定義と食い違っていないこと。
 */
final class CardTextsTest extends TestCase
{
    private const TOKENS = ['power', 'fame', 'cost', 'earth', 'solar', 'milky', 'virgo', 'laniakea', 'unknown', 'charm', 'flag', 'crew'];

    public function testEveryCardHasTextsMatchingItsDefinition(): void
    {
        self::assertCount(58, CardTexts::all());
        foreach (CardDefinitions::all() as $cardNo => $definition) {
            $texts = CardTexts::get($cardNo);
            self::assertNotNull($texts, $cardNo);
            self::assertSame($definition->nameEn, $texts['name'], "{$cardNo} の英語名（ログに出る名前）と一致");

            $timings = [];
            foreach ($texts['effects'] as $effect) {
                self::assertNotSame('', trim($effect['text']), $cardNo);
                array_push($timings, ...$effect['timings']);
            }
            self::assertSame(
                array_map(static fn(EffectTiming $t): string => $t->value, $definition->timings()),
                array_map(static fn(EffectTiming $t): string => $t->value, $timings),
                "{$cardNo} の効果タイミング",
            );
        }
    }

    public function testTextsOnlyUseKnownIconTokens(): void
    {
        foreach (CardTexts::all() as $cardNo => $texts) {
            foreach ($texts['effects'] as $effect) {
                preg_match_all('/\{(\w+)\}/', $effect['text'], $m);
                foreach ($m[1] as $token) {
                    self::assertContains($token, self::TOKENS, "{$cardNo}: {{$token}}");
                }
                self::assertStringNotContainsString('[', $effect['text'], "{$cardNo}: 角括弧表記が残っている");
            }
        }
    }

    public function testInfoViewCarriesTranslatedTextAndStats(): void
    {
        $info = CardInfoView::build(static fn(string $text): string => strtoupper($text));

        self::assertCount(58, $info);
        self::assertSame('ROBOT ARM', $info[5]['name']);
        self::assertSame('Robot Arm', $info[5]['nameEn']);
        self::assertSame('ロボットアーム', $info[5]['nameJa']);
        self::assertSame(2, $info[5]['cost']);
        self::assertSame(['SOLAR_SYSTEM'], $info[5]['symbols']);
        self::assertSame(['ON_DRAW'], $info[5]['effects'][0]['timings']);
        self::assertNull($info[57]['cost'], 'お守りにコストは無い');
        self::assertSame([], $info[1]['effects']);
        self::assertSame(['ON_DRAW', 'DURING_EXPLORE'], $info[7]['effects'][0]['timings']);
    }
}
