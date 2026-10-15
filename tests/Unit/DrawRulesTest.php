<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\DrawRules;
use Bga\Games\MinimumJourney\Material\Symbol;
use PHPUnit\Framework\TestCase;

/**
 * ドロー時効果の解決順の純粋な規則（010 §5・§6.2、008 §1.5）。
 */
final class DrawRulesTest extends TestCase
{
    public function testFarthestFirstPutsHolderLast(): void
    {
        // 席順 1,2,3,4（時計回り）。所持者 2 からの距離: 2=0、3=1、4=2、1=3 → 大きい順 1,4,3,2（D4）
        self::assertSame([1, 4, 3, 2], DrawRules::farthestFirst([1, 2, 3, 4], 2));
        self::assertSame([4, 3, 2, 1], DrawRules::farthestFirst([1, 2, 3, 4], 1));
    }

    public function testFarthestFirstThreePlayers(): void
    {
        // 所持者 3（席順の最後）からの距離: 3=0、1=1、2=2 → 2,1,3
        self::assertSame([2, 1, 3], DrawRules::farthestFirst([1, 2, 3], 3));
    }

    public function testFarthestFirstWithoutHolderKeepsSeatOrder(): void
    {
        self::assertSame([1, 2, 3], DrawRules::farthestFirst([1, 2, 3], null));
    }

    public function testJammingAllowsOnlyVirgoCards(): void
    {
        $rule = 'ALLOW_SYMBOL:VIRGO_CLUSTER';
        self::assertTrue(DrawRules::passes($rule, [Symbol::VirgoCluster], 1, 2));
        self::assertFalse(DrawRules::passes($rule, [Symbol::SolarSystem], 1, 2));
        // 051 のように複数シンボルを持つカードは、乙女座銀河団を含むので通る
        self::assertTrue(DrawRules::passes($rule, [Symbol::Earth, Symbol::VirgoCluster], 1, 2));
    }

    public function testSuperflareAllowsOnlySameOwner(): void
    {
        self::assertTrue(DrawRules::passes('ALLOW_SAME_OWNER', [Symbol::Laniakea], 5, 5));
        self::assertFalse(DrawRules::passes('ALLOW_SAME_OWNER', [Symbol::Laniakea], 6, 5));
    }

    public function testUnknownRuleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DrawRules::passes('NOPE', [], 1, 1);
    }
}
