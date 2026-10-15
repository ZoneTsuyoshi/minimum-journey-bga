<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\CostContext;
use Bga\Games\MinimumJourney\Flow\ExploreRules;
use Bga\Games\MinimumJourney\Material\Symbol;
use PHPUnit\Framework\TestCase;

/**
 * 探査中の効果による実効コストと探査禁止（008 §4）。TC-X-1〜TC-X-3、025・026 の禁止、012 の合計。
 */
final class CostContextTest extends TestCase
{
    private const ALL = [Symbol::Earth, Symbol::SolarSystem, Symbol::MilkyWay, Symbol::VirgoCluster, Symbol::Laniakea];

    /**
     * 006（天の川・ラニアケア -1）、021（乙女座 -1）、038（最初 -1 = 全て）。
     */
    private static function stacked(): CostContext
    {
        return new CostContext([
            ['amount' => -1, 'symbols' => [Symbol::MilkyWay, Symbol::Laniakea]],
            ['amount' => -1, 'symbols' => [Symbol::VirgoCluster]],
            ['amount' => -1, 'symbols' => null],
        ]);
    }

    public function testTcX1StackedModifiers(): void
    {
        $c = self::stacked();
        self::assertSame(4, $c->effective(6, [Symbol::VirgoCluster]), '036');
        self::assertSame(6, $c->effective(8, [Symbol::Laniakea]), '046');
        self::assertSame(2, $c->effective(4, [Symbol::MilkyWay]), '019');
        self::assertSame(5, $c->effective(8, self::ALL), '051 は 006 と 021 を 1 回ずつ');
        self::assertSame(1, $c->effective(2, [Symbol::SolarSystem]), '004');
        self::assertSame(9, $c->effective(10, [Symbol::Unknown]), '056');
    }

    public function testTcX1PileTopsAreJudgedByThePileSymbol(): void
    {
        $t = ExploreRules::targets(99, [], null, [2 => 3, 4 => 3, 6 => 3, 8 => 3], self::stacked());
        self::assertSame([1, 2, 4, 6], array_column($t['deckTargets'], 'effectiveCost'), '8 の山は [ラニアケア超銀河団] として判定し 021 は作用しない');
    }

    public function testTcX2FloorIsZero(): void
    {
        $c = new CostContext([
            ['amount' => -2, 'symbols' => null],
            ['amount' => -1, 'symbols' => null],
        ]);
        self::assertSame(0, $c->effective(2, [Symbol::SolarSystem]));
        $t = ExploreRules::targets(0, [['cardId' => 4, 'slot' => 0, 'cost' => 2]], null, [], $c);
        self::assertSame([4], array_column($t['rowTargets'], 'cardId'), '探査出力 0 でも探査できる');
    }

    public function testTcX3PlusAndMinusCancel(): void
    {
        $c = new CostContext([['amount' => 1, 'symbols' => null], ['amount' => -1, 'symbols' => null]]);
        self::assertSame(4, $c->effective(4, [Symbol::MilkyWay]));
    }

    public function testUnknownBandWith047AndFirstInOrder(): void
    {
        $c = new CostContext([['amount' => -2, 'symbols' => [Symbol::Unknown]], ['amount' => -1, 'symbols' => null]]);
        self::assertSame(7, $c->effective(10, [Symbol::Unknown]));
    }

    public function testOversizedEngineBansSolarAndMilkyWayRowCardsButNotPilesOrUnknown(): void
    {
        $c = new CostContext([], [['scope' => 'EXPLORE_FIELD', 'symbols' => [Symbol::SolarSystem, Symbol::MilkyWay], 'except_unknown' => false]]);
        $row = [
            ['cardId' => 5, 'slot' => 0, 'cost' => 2, 'symbols' => [Symbol::SolarSystem]],
            ['cardId' => 36, 'slot' => 0, 'cost' => 6, 'symbols' => [Symbol::VirgoCluster]],
            ['cardId' => 51, 'slot' => 0, 'cost' => 8, 'symbols' => self::ALL],
        ];
        $t = ExploreRules::targets(10, $row, 56, [2 => 1, 4 => 1, 6 => 1, 8 => 1], $c);
        self::assertSame([36], array_column($t['rowTargets'], 'cardId'));
        self::assertSame([2, 4, 6, 8], array_column($t['deckTargets'], 'cost'), '山札の上は制限の対象外');
        self::assertNotNull($t['unknownTarget']);
    }

    public function testCloudedTelescopeBansTheRowButNotTheUnknownBandOrPiles(): void
    {
        $c = new CostContext([], [['scope' => 'ROW', 'symbols' => null, 'except_unknown' => true]]);
        $row = [['cardId' => 5, 'slot' => 0, 'cost' => 2, 'symbols' => [Symbol::SolarSystem]]];
        $t = ExploreRules::targets(10, $row, 56, [2 => 1], $c);
        self::assertSame([], $t['rowTargets']);
        self::assertNotNull($t['unknownTarget']);
        self::assertSame([2], array_column($t['deckTargets'], 'cost'));
    }

    public function testAdditionalExploreUsesTheSumOfRecordedCosts(): void
    {
        // TC-012-1: 出力 6、1 枚目のコスト 2（sumCost 2）。コスト 4 は可（2 + 4 = 6）、コスト 6 は不可（8）
        $row = [
            ['cardId' => 21, 'slot' => 0, 'cost' => 4],
            ['cardId' => 31, 'slot' => 0, 'cost' => 6],
        ];
        $t = ExploreRules::targets(6, $row, null, [], null, 2);
        self::assertSame([21], array_column($t['rowTargets'], 'cardId'));
    }
}
