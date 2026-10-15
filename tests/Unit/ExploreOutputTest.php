<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\ExploreOutput;
use Bga\Games\MinimumJourney\Flow\PowerMod;
use Bga\Games\MinimumJourney\Flow\PrepCardInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 探査出力の計算の純粋な部分（011 §6、001 §6-1）。合計 → お守り → 半減 → 下限 0。
 */
final class ExploreOutputTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, bool, int, int}>
     */
    public static function decidedCases(): iterable
    {
        // [合計, お守りの探査力, 半減, 期待する確定出力, 期待する準備中の出力]
        yield 'plain sum' => [5, 0, false, 5, 5];
        yield 'charm is added before halving' => [5, 1, true, 3, 6];
        yield 'halving floors' => [4, 0, true, 2, 4];
        yield 'halving floors odd' => [3, 0, true, 1, 3];
        yield 'one is halved to zero' => [1, 0, true, 0, 1];
        yield 'empty prep with the charm (E2, E4)' => [0, 1, false, 1, 1];
        yield 'empty prep without the charm' => [0, 0, false, 0, 0];
        yield 'floor at zero' => [-3, 1, false, 0, 0];
        yield 'floor at zero when halved' => [-3, 0, true, 0, 0];
    }

    #[DataProvider('decidedCases')]
    public function testDecidedAndNow(int $total, int $charm, bool $halved, int $decided, int $now): void
    {
        self::assertSame($decided, ExploreOutput::decided($total, $charm, $halved));
        self::assertSame($now, ExploreOutput::now($total, $charm), '準備中の出力は半減を含めない');
    }

    public function testCharmPowerIsZeroOnTheFirstRoundOnly(): void
    {
        self::assertSame(0, ExploreOutput::charmPower(1, 1, 1));
        self::assertSame(1, ExploreOutput::charmPower(1, 1, 2));
        self::assertSame(1, ExploreOutput::charmPower(1, 1, 7));
        self::assertSame(0, ExploreOutput::charmPower(0, null, 3), '厄除けは常に 0');
    }

    public function testPowerModHookReturnsPrintedPowerAndIgnoresSideways(): void
    {
        $cards = [
            new PrepCardInput(29, 4, false),
            new PrepCardInput(30, 4, true),
            new PrepCardInput(2, 1, false),
        ];
        self::assertSame([4, 0, 1], PowerMod::apply($cards, 1));
        self::assertSame([], PowerMod::apply([], 1));
    }
}
