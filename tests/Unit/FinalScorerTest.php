<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\FinalScorer;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\Symbol;
use PHPUnit\Framework\TestCase;

/**
 * 名声の計算と同点処理の符号化（001 §9、008 §3.2、007 §11.2）。
 */
final class FinalScorerTest extends TestCase
{
    private static function fame(int $cardNo): int
    {
        return CardDefinitions::get(sprintf('%03d', $cardNo))->fame ?? 0;
    }

    public function testBaseFameSumsPrintedFame(): void
    {
        // 国旗 3 + 乗組員 2 + 乗組員 2 + 初期探査船 1 = 8
        self::assertSame(8, FinalScorer::baseFame([1, 2, 2, 3]));
        self::assertSame(self::fame(55) + self::fame(56), FinalScorer::baseFame([55, 56]));
        self::assertSame(0, FinalScorer::baseFame([]));
    }

    public function testCharmCardPrintedFameIsNotAdded(): void
    {
        self::assertSame(3, FinalScorer::baseFame([1, 57]));
        self::assertSame(3, FinalScorer::baseFame([1, 58]));
    }

    public function testCharmHolderGetsExactlyPlusOne(): void
    {
        $holder = FinalScorer::score([1, 2], true, false, 0);
        $other = FinalScorer::score([1, 2], false, false, 0);
        self::assertSame(5, $holder->base);
        self::assertSame(1, $holder->charmBonus);
        self::assertSame(6, $holder->fame());
        self::assertSame(0, $other->charmBonus);
        self::assertSame(5, $other->fame());
    }

    public function testModifierIsAddedAndDefaultsToZeroUntilCardEffects(): void
    {
        self::assertSame(7, FinalScorer::score([1, 2], false, false, 2)->fame());
        self::assertSame(0, FinalScorer::score([1, 2], false, false)->modifier);
    }

    public function testSymbolCountsCountEachSymbolOncePerCard(): void
    {
        // 051 は 5 シンボル（地球・天の川・ラニアケア・乙女座・太陽系）を持つ
        $counts = FinalScorer::symbolCounts([51, 1, 1]);
        self::assertSame(['laniakea' => 1, 'virgo' => 1, 'milkyWay' => 1, 'solar' => 1], $counts, '地球は同点処理に使わない');

        $only = FinalScorer::symbolCounts([4, 4, 56]);
        self::assertSame(['laniakea' => 0, 'virgo' => 0, 'milkyWay' => 0, 'solar' => 2], $only);
    }

    public function testCharmCardDoesNotAffectSymbolCounts(): void
    {
        self::assertSame(FinalScorer::symbolCounts([4]), FinalScorer::symbolCounts([4, 57, 58]));
        self::assertTrue(CardDefinitions::get('057')?->hasSymbol(Symbol::Charm));
    }

    public function testAuxEncoding(): void
    {
        $zero = ['laniakea' => 0, 'virgo' => 0, 'milkyWay' => 0, 'solar' => 0];
        self::assertSame(99999999, FinalScorer::aux(false, $zero));
        self::assertSame(199999999, FinalScorer::aux(true, $zero));
        self::assertSame(
            1 * 100000000 + 96 * 1000000 + 97 * 10000 + 98 * 100 + 99,
            FinalScorer::aux(true, ['laniakea' => 3, 'virgo' => 2, 'milkyWay' => 1, 'solar' => 0]),
        );
    }

    public function testAuxOrdersTheTieBreakersInPriority(): void
    {
        $few = static fn(int $l, int $v, int $m, int $s): array => ['laniakea' => $l, 'virgo' => $v, 'milkyWay' => $m, 'solar' => $s];

        // 1. 未確認帯域の獲得が、シンボルの少なさより優先
        self::assertGreaterThan(FinalScorer::aux(false, $few(0, 0, 0, 0)), FinalScorer::aux(true, $few(9, 9, 9, 9)));
        // 2. ラニアケアが少ない方が、乙女座・天の川・太陽系より優先
        self::assertGreaterThan(FinalScorer::aux(false, $few(2, 0, 0, 0)), FinalScorer::aux(false, $few(1, 9, 9, 9)));
        // 3. 乙女座 → 4. 天の川 → 5. 太陽系
        self::assertGreaterThan(FinalScorer::aux(false, $few(0, 2, 0, 0)), FinalScorer::aux(false, $few(0, 1, 9, 9)));
        self::assertGreaterThan(FinalScorer::aux(false, $few(0, 0, 2, 0)), FinalScorer::aux(false, $few(0, 0, 1, 9)));
        self::assertGreaterThan(FinalScorer::aux(false, $few(0, 0, 0, 2)), FinalScorer::aux(false, $few(0, 0, 0, 1)));
        // 全て同じなら同じ値（勝利を分かち合う）
        self::assertSame(FinalScorer::aux(false, $few(1, 2, 3, 4)), FinalScorer::aux(false, $few(1, 2, 3, 4)));
    }

    public function testAuxFitsInSigned32BitAndCapsCounts(): void
    {
        $huge = ['laniakea' => 500, 'virgo' => 500, 'milkyWay' => 500, 'solar' => 500];
        self::assertSame(0, FinalScorer::aux(false, $huge));
        $zero = ['laniakea' => 0, 'virgo' => 0, 'milkyWay' => 0, 'solar' => 0];
        self::assertLessThanOrEqual(2147483647, FinalScorer::aux(true, $zero));
    }

    public function testScoreCombinesEverything(): void
    {
        // 国旗 3、乗組員 2、未確認帯域 10 + 太陽系の 004（名声 0）
        $score = FinalScorer::score([1, 2, 56, 4], true, true, 0);
        self::assertSame(15, $score->base);
        self::assertSame(16, $score->fame());
        self::assertTrue($score->unknownAcquired);
        self::assertSame(1, $score->symbols['solar']);
        self::assertSame(FinalScorer::aux(true, $score->symbols), $score->aux);
    }

    public function testEarthCountIsCountedSeparatelyAndNotUsedForTies(): void
    {
        // 051 は地球を含む 5 シンボル。地球の枚数が違っても aux は変わらない（001 §9-3）
        self::assertSame(1, FinalScorer::earthCount([51]));
        self::assertSame(0, FinalScorer::earthCount([]));
        $a = FinalScorer::score([4], false, false, 0);
        $b = FinalScorer::score([4, 1, 1], false, false, 0);
        self::assertSame($a->symbols, $b->symbols);
        self::assertSame($a->aux, $b->aux, '国旗・乗組員のシンボルが同点処理の 4 種に含まれなければ aux は同じ');
    }

    public function testMultiSymbolCardLosesToSingleSymbolCardOnTheSameTier(): void
    {
        // 051（4 種すべて）は 004（太陽系のみ）より同点処理で不利。全く同じデッキなら同値（共同勝利）
        $multi = FinalScorer::score([51], false, false, 0);
        $single = FinalScorer::score([4], false, false, 0);
        self::assertGreaterThan($multi->aux, $single->aux);
        self::assertSame(FinalScorer::score([4, 1], false, false, 0)->aux, FinalScorer::score([1, 4], false, false, 0)->aux);
    }
}
