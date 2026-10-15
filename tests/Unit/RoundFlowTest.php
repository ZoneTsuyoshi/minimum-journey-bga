<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 状態遷移の純粋な判定（BGA にも DB にも依存しない。仕様書 006）。
 */
final class RoundFlowTest extends TestCase
{
    public function testAllChosen(): void
    {
        self::assertFalse(RoundFlow::allChosen([1, 2], [1 => PrepChoice::Stop, 2 => null]));
        self::assertFalse(RoundFlow::allChosen([1, 2], [1 => PrepChoice::Stop]));
        self::assertTrue(RoundFlow::allChosen([1, 2], [1 => PrepChoice::Stop, 2 => PrepChoice::Draw]));
        self::assertTrue(RoundFlow::allChosen([], []));
    }

    /**
     * @param array<int, PrepChoice|null> $choices
     */
    #[DataProvider('revealCases')]
    public function testRevealTransition(array $choices, string $expected): void
    {
        self::assertSame($expected, RoundFlow::revealTransition($choices));
    }

    /**
     * @return iterable<string, array{array<int, PrepChoice|null>, string}>
     */
    public static function revealCases(): iterable
    {
        yield 'all stop' => [[1 => PrepChoice::Stop, 2 => PrepChoice::Stop, 3 => PrepChoice::Stop], 'allStop'];
        yield 'nobody selected (all already finished)' => [[], 'allStop'];
        yield 'one draw' => [[1 => PrepChoice::Draw, 2 => PrepChoice::Stop], 'soloDraw'];
        yield 'two draws' => [[1 => PrepChoice::Draw, 2 => PrepChoice::Draw, 3 => PrepChoice::Stop], 'resolve'];
        yield 'null choices are not draws' => [[1 => null, 2 => PrepChoice::Stop], 'allStop'];
    }

    public function testExploreTurnsFollowTheOrderAndEnd(): void
    {
        $order = RoundFlow::placeholderExploreOrder([5, 6, 7]);
        self::assertSame([5, 6, 7], $order);
        self::assertSame(5, RoundFlow::currentExplorer($order, 0));
        self::assertSame(7, RoundFlow::currentExplorer($order, 2));
        self::assertNull(RoundFlow::currentExplorer($order, 3));
        self::assertNull(RoundFlow::currentExplorer([], 0));
    }

    public function testPlaceholderGameOver(): void
    {
        self::assertFalse(RoundFlow::isGameOver(1));
        self::assertFalse(RoundFlow::isGameOver(RoundFlow::PLACEHOLDER_ROUND_LIMIT - 1));
        self::assertTrue(RoundFlow::isGameOver(RoundFlow::PLACEHOLDER_ROUND_LIMIT));
        self::assertTrue(RoundFlow::isGameOver(5, 5));
    }

    /**
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function progressionCases(): iterable
    {
        yield 'start' => [8, 8, 38, 38, 0];
        yield 'pile8 half' => [8, 4, 38, 38, 50];
        yield 'all piles drawn evenly' => [10, 5, 40, 20, 50];
        yield 'other piles drive (0.8 weight)' => [8, 8, 40, 0, 80];
        yield 'cap at 99' => [8, 0, 38, 0, 99];
        yield 'empty piles at start' => [0, 0, 0, 0, 0];
        yield 'never negative' => [8, 9, 38, 40, 0];
    }

    #[DataProvider('progressionCases')]
    public function testProgression(int $pile8Initial, int $pile8, int $totalInitial, int $total, int $expected): void
    {
        self::assertSame($expected, RoundFlow::progression($pile8Initial, $pile8, $totalInitial, $total));
    }

    public function testProgressionIsMonotonicAsPilesShrink(): void
    {
        $previous = 0;
        for ($total = 38; $total >= 0; $total--) {
            $value = RoundFlow::progression(8, max(0, $total - 30), 38, $total);
            self::assertGreaterThanOrEqual($previous, $value);
            self::assertLessThanOrEqual(99, $value);
            $previous = $value;
        }
    }
}
