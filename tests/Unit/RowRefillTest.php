<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\RowRefill;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 列の補充枚数（001 §7-3、E7・E9）と補充後の分岐（006 §2.5）。
 */
final class RowRefillTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function cases(): iterable
    {
        yield 'full row needs nothing' => [3, 3, 10, 0];
        yield 'two taken' => [3, 1, 10, 2];
        yield 'empty row' => [4, 0, 10, 4];
        yield 'pile has fewer than needed' => [3, 0, 2, 2];
        yield 'pile empty' => [2, 0, 0, 0];
        yield 'row above the regular size (011)' => [2, 3, 10, 0];
    }

    #[DataProvider('cases')]
    public function testCount(int $rowSize, int $rowCount, int $deckCount, int $expected): void
    {
        self::assertSame($expected, RowRefill::count($rowSize, $rowCount, $deckCount));
    }

    public function testTransitionAfterRefill(): void
    {
        self::assertSame('end', RoundFlow::afterRefill(true));
        self::assertSame('nextRound', RoundFlow::afterRefill(false));
    }
}
