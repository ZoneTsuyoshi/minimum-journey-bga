<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\RowCompact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 列の左詰め（作者の指示 2026-11-18）。
 */
final class RowCompactTest extends TestCase
{
    /**
     * @return iterable<string, array{array<int, int>, array<int, int>}>
     */
    public static function cases(): iterable
    {
        yield 'empty row' => [[], []];
        yield 'no hole' => [[10 => 0, 11 => 1, 12 => 2], []];
        yield 'hole on the left' => [[11 => 1, 12 => 2], [11 => 0, 12 => 1]];
        yield 'hole in the middle' => [[10 => 0, 12 => 2], [12 => 1]];
        yield 'only the right end is left' => [[12 => 2], [12 => 0]];
        yield 'row extended by 011, leftmost taken' => [[11 => 1, 12 => 2], [11 => 0, 12 => 1]];
        yield 'holes on both sides' => [[10 => 1, 11 => 3, 12 => 4], [10 => 0, 11 => 1, 12 => 2]];
        yield 'unsorted input keeps the order of slots' => [[12 => 4, 10 => 1, 11 => 3], [10 => 0, 11 => 1, 12 => 2]];
    }

    /**
     * @param array<int, int> $slots
     * @param array<int, int> $expected
     */
    #[DataProvider('cases')]
    public function testMoves(array $slots, array $expected): void
    {
        self::assertSame($expected, RowCompact::moves($slots));
    }
}
