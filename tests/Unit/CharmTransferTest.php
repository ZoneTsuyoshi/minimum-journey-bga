<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\CharmTransfer;
use PHPUnit\Framework\TestCase;

/**
 * お守りの新所有者（001 §7-1）。探査出力が最小 → 同点は探査手番が遅い方。
 */
final class CharmTransferTest extends TestCase
{
    public function testLowestOutputGetsTheCharm(): void
    {
        self::assertSame(2, CharmTransfer::newHolder([1 => 5, 2 => 3, 3 => 4], [1, 2, 3]));
    }

    public function testTieGoesToTheLaterExplorer(): void
    {
        self::assertSame(3, CharmTransfer::newHolder([1 => 3, 2 => 5, 3 => 3], [1, 2, 3]));
        // 手番の順が違えば結果も変わる（席順ではなく探査手番で決める）
        self::assertSame(1, CharmTransfer::newHolder([1 => 3, 2 => 5, 3 => 3], [3, 2, 1]));
    }

    public function testAllTiedGoesToTheLastExplorer(): void
    {
        self::assertSame(4, CharmTransfer::newHolder([1 => 0, 2 => 0, 3 => 0, 4 => 0], [2, 3, 1, 4]));
        self::assertSame(1, CharmTransfer::newHolder([1 => 0, 2 => 0, 3 => 0, 4 => 0], [4, 3, 2, 1]));
    }

    public function testOnlyTheLowestMattersEvenIfLaterExplorersAreHigher(): void
    {
        self::assertSame(1, CharmTransfer::newHolder([1 => 1, 2 => 2, 3 => 2, 4 => 9], [1, 2, 3, 4]));
    }

    public function testNoPlayers(): void
    {
        self::assertNull(CharmTransfer::newHolder([], []));
    }
}
