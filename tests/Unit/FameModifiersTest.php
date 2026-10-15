<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\FameModifiers;
use Bga\Games\MinimumJourney\Flow\FinalScorer;
use PHPUnit\Framework\TestCase;

/**
 * 名声変動（008 §3。032・033・041・042・045・047）。TC-F-1〜TC-F-5。
 */
final class FameModifiersTest extends TestCase
{
    private const DECK = [1, 2, 2, 3, 4, 19, 32, 33, 42, 45, 47, 51, 56];

    public function testTcF1TotalOfThirteenCards(): void
    {
        self::assertSame(28, FinalScorer::baseFame(self::DECK));
        // 032: [地球] 5 枚、033: [太陽系] 2 枚、042: min(2, 4) = 2、045: 5 種、047: 056 があるので 3
        self::assertSame(5 + 2 + 2 + 5 + 3, FameModifiers::total(self::DECK, false));
        self::assertSame(45, FinalScorer::score(self::DECK, false, false)->fame());
        self::assertSame(46, FinalScorer::score(self::DECK, true, false)->fame(), 'お守りの所持者は +1');
    }

    public function testTcF2ExcludedCardsAreNotCounted(): void
    {
        $deck = array_values(array_diff(self::DECK, [32]));
        // 032 の +5 が消える。[天の川銀河] は 019・033・051 の 3 枚だが、ペア数は min(2, 3) = 2
        self::assertSame(28, FinalScorer::baseFame($deck));
        self::assertSame(2 + 2 + 5 + 3, FameModifiers::total($deck, false));
        self::assertSame(40, FinalScorer::score($deck, false, false)->fame());
    }

    public function testTcF3AttachedCardIsCountedOnceAsASeparateCard(): void
    {
        self::assertSame(5, FinalScorer::baseFame([46, 18]));
        self::assertSame(0, FameModifiers::total([46, 18], false));
    }

    public function testTcF4OnlyTheCharmHolderGetsTheBoxBonus(): void
    {
        $deck = [41, 1];
        self::assertSame(1, FameModifiers::total($deck, true));
        self::assertSame(0, FameModifiers::total($deck, false));
        self::assertSame(3 + 3 + 1 + 1, FinalScorer::score($deck, true, false)->fame(), '印字 6 + 041 の +1 + お守りの +1');
        self::assertSame(6, FinalScorer::score($deck, false, false)->fame());
    }

    public function testTcF5ExcludedCardPrintedFameIsNotCounted(): void
    {
        self::assertSame(3, FinalScorer::baseFame([1]));
        self::assertSame(3 + 2, FinalScorer::baseFame([1, 13]));
    }

    public function testModifiersOnlyApplyWhenTheCardIsInTheDeck(): void
    {
        self::assertSame(0, FameModifiers::total([1, 2, 3, 51, 56], true));
        self::assertSame(0, FameModifiers::total([47], false), '056 が無ければ 047 は加算しない');
        self::assertSame(3, FameModifiers::total([47, 56], false));
        self::assertSame(0, FameModifiers::total([56], false), '047 が無ければ 056 だけでは加算しない');
    }

    public function testKindCountOf045IgnoresTheCharmAndUnknownBand(): void
    {
        // [地球] [太陽系] [天の川銀河] のカード（045 自身は [乙女座銀河団]）と、お守り・[未確認帯域] は種類に入らない
        self::assertSame(1, FameModifiers::total([45, 57], false), '045 自身の [乙女座銀河団] だけ');
        self::assertSame(1, FameModifiers::total([45, 56], false));
        self::assertSame(4, FameModifiers::total([45, 1, 4, 19], false));
        self::assertSame(5, FameModifiers::total([45, 51], false), '051 は 5 シンボルを持つ');
    }

    public function testPairCountOf042UsesTheSmallerSide(): void
    {
        // 042 自身は [乙女座銀河団]。[太陽系] 3 枚、[天の川銀河] 1 枚 → 1 ペア
        self::assertSame(1, FameModifiers::total([42, 4, 5, 6, 19], false));
        self::assertSame(0, FameModifiers::total([42, 4, 5], false));
    }
}
