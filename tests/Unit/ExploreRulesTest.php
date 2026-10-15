<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\ExploreRules;
use PHPUnit\Framework\TestCase;

/**
 * 探査できる対象の判定（001 §6-3）。探査コスト ≤ 探査出力。
 */
final class ExploreRulesTest extends TestCase
{
    private const ROW = [
        ['cardId' => 11, 'slot' => 0, 'cost' => 2],
        ['cardId' => 12, 'slot' => 2, 'cost' => 2],
        ['cardId' => 21, 'slot' => 0, 'cost' => 4],
        ['cardId' => 31, 'slot' => 1, 'cost' => 6],
        ['cardId' => 41, 'slot' => 0, 'cost' => 8],
    ];
    private const DECKS = [2 => 5, 4 => 5, 6 => 0, 8 => 3];

    public function testCostEqualToOutputIsAllowed(): void
    {
        $t = ExploreRules::targets(4, self::ROW, 99, self::DECKS);

        self::assertSame([11, 12, 21], array_column($t['rowTargets'], 'cardId'));
        self::assertNull($t['unknownTarget']);
        self::assertSame([2, 4], array_column($t['deckTargets'], 'cost'));
    }

    public function testRowTargetsKeepSlotAndEffectiveCost(): void
    {
        $t = ExploreRules::targets(2, self::ROW, null, self::DECKS);

        self::assertSame([['cardId' => 12, 'slot' => 2, 'cost' => 2, 'effectiveCost' => 2]], [$t['rowTargets'][1]]);
    }

    public function testUnknownBandNeedsTen(): void
    {
        self::assertNull(ExploreRules::targets(9, [], 99, self::DECKS)['unknownTarget']);
        self::assertSame(['cardId' => 99, 'effectiveCost' => 10], ExploreRules::targets(10, [], 99, self::DECKS)['unknownTarget']);
        self::assertNull(ExploreRules::targets(20, [], null, self::DECKS)['unknownTarget'], '獲得済みなら対象にならない');
    }

    public function testDeckTopIsATargetEvenWhenTheRowIsEmptyAndExcludesEmptyPiles(): void
    {
        $t = ExploreRules::targets(8, [], null, self::DECKS);

        self::assertSame([], $t['rowTargets']);
        self::assertSame(
            [['cost' => 2, 'effectiveCost' => 2, 'count' => 5], ['cost' => 4, 'effectiveCost' => 4, 'count' => 5], ['cost' => 8, 'effectiveCost' => 8, 'count' => 3]],
            $t['deckTargets'],
            '枚数 0 の山（6）は含めない',
        );
    }

    public function testNothingToExploreMeansEmpty(): void
    {
        self::assertTrue(ExploreRules::isEmpty(ExploreRules::targets(1, self::ROW, 99, self::DECKS)));
        self::assertTrue(ExploreRules::isEmpty(ExploreRules::targets(0, self::ROW, 99, self::DECKS)));
        self::assertFalse(ExploreRules::isEmpty(ExploreRules::targets(2, self::ROW, 99, self::DECKS)));
        self::assertTrue(ExploreRules::isEmpty(ExploreRules::targets(20, [], null, [2 => 0, 4 => 0, 6 => 0, 8 => 0])));
    }
}
