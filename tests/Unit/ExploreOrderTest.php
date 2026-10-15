<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\ExploreOrder;
use PHPUnit\Framework\TestCase;

/**
 * 探査手番の決定（001 §6-2）の純粋な部分。
 */
final class ExploreOrderTest extends TestCase
{
    private const ALICE = 1;
    private const JESSE = 2;
    private const BRIAN = 3;
    private const KOUKI = 4;

    /**
     * 001 §8-1（RB p.2 の例）: 手番は ジェシー(1) → アリス(2) → コウキ(3) → ブライアン(4)。
     */
    public function testRulebookExample(): void
    {
        $order = ExploreOrder::decide(
            [self::ALICE, self::JESSE, self::BRIAN, self::KOUKI],
            [self::ALICE => 3, self::JESSE => 2, self::BRIAN => 3, self::KOUKI => 3],
            [self::ALICE => 5, self::JESSE => 7, self::BRIAN => 6, self::KOUKI => 6],
            self::KOUKI,
        );

        self::assertSame([self::JESSE, self::ALICE, self::KOUKI, self::BRIAN], $order);
        // 手番の番号に直すと アリス 2、ジェシー 1、ブライアン 4、コウキ 3
        self::assertSame(2, array_search(self::ALICE, $order, true) + 1);
        self::assertSame(1, array_search(self::JESSE, $order, true) + 1);
        self::assertSame(4, array_search(self::BRIAN, $order, true) + 1);
        self::assertSame(3, array_search(self::KOUKI, $order, true) + 1);
    }

    public function testPrepCountIsTheFirstKey(): void
    {
        // 枚数が少ない方が、探査出力が大きくても先
        $order = ExploreOrder::decide([1, 2, 3], [1 => 3, 2 => 1, 3 => 2], [1 => 0, 2 => 99, 3 => 5], 1);
        self::assertSame([2, 3, 1], $order);
    }

    public function testOutputIsTheSecondKey(): void
    {
        $order = ExploreOrder::decide([1, 2, 3], [1 => 2, 2 => 2, 3 => 2], [1 => 6, 2 => 4, 3 => 5], 1);
        self::assertSame([2, 3, 1], $order);
    }

    public function testDistanceFromTheCharmHolderClockwiseIsTheLastKey(): void
    {
        // 全員が同点: お守り所持者(3)から時計回り 3, 4, 1, 2
        $seat = [1, 2, 3, 4];
        $same = [1 => 2, 2 => 2, 3 => 2, 4 => 2];
        self::assertSame([3, 4, 1, 2], ExploreOrder::decide($seat, $same, $same, 3));
        self::assertSame([1, 2, 3, 4], ExploreOrder::decide($seat, $same, $same, 1));
        self::assertSame([4, 1, 2, 3], ExploreOrder::decide($seat, $same, $same, 4));
    }

    public function testAllStopRoundWithCharmHolderOutputOne(): void
    {
        // E4: 全員 0 枚、お守り所持者(2)だけ出力 1 → 出力の小さい 3, 1 が先（所持者 2 から近い順）、所持者が最後
        $order = ExploreOrder::decide([1, 2, 3], [1 => 0, 2 => 0, 3 => 0], [1 => 0, 2 => 1, 3 => 0], 2);
        self::assertSame([3, 1, 2], $order);
    }

    public function testThreePlayers(): void
    {
        $order = ExploreOrder::decide([10, 20, 30], [10 => 1, 20 => 1, 30 => 1], [10 => 2, 20 => 2, 30 => 2], 20);
        self::assertSame([20, 30, 10], $order);
    }
}
