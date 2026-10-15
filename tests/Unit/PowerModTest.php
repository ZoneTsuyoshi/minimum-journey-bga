<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\ExploreOutput;
use Bga\Games\MinimumJourney\Flow\PowerMod;
use Bga\Games\MinimumJourney\Flow\PrepCardInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 探査力変動の段階エンジン（011 §6）。011 §6-2 の検証例 E1〜E12 と、008 の探査力変動カードのテストケース（TC-NNN-k）。
 */
final class PowerModTest extends TestCase
{
    /** 横向きを表すマーカー（カード番号に足す）。 */
    private const SIDE = 1000;

    /**
     * @param list<int|array{int, int}> $spec カード番号。横向きは番号 + SIDE。046 は [46, 付加カード番号]
     * @return list<PrepCardInput>
     */
    private static function prep(array $spec): array
    {
        $cards = [];
        foreach ($spec as $item) {
            if (is_array($item)) {
                $cards[] = PrepCardInput::of($item[0], false, $item[1]);
            } elseif ($item >= self::SIDE) {
                $cards[] = PrepCardInput::of($item - self::SIDE, true);
            } else {
                $cards[] = PrepCardInput::of($item);
            }
        }
        return $cards;
    }

    /**
     * @param list<int|array{int, int}> $spec
     */
    private static function total(array $spec, bool $holdsCharm = false, ?int $left = null, int $round = 2): int
    {
        return array_sum(PowerMod::apply(self::prep($spec), $round, $holdsCharm, $left));
    }

    /**
     * 011 §6-2 の検証例（お守り・半減の前の合計）。
     *
     * @return iterable<string, array{list<int|array{int, int}>, int}>
     */
    public static function spec011(): iterable
    {
        yield 'E1 048 right of 030' => [[29, 30, 48], 6];
        yield "E1' 048 left of 030" => [[48, 29, 30], 6];
        yield 'E2 019 and 030' => [[19, 29, 30], 6];
        yield 'E3 019 and 048' => [[19, 29, 48], 7];
        yield 'E4a 031 after rewritten' => [[19, 18, 31], 5];
        yield 'E4b 031 after 030' => [[3, 30, 31], 9];
        yield 'E4c 031 with 048' => [[29, 31, 48], 8];
        yield 'E4d 031 negated by 030' => [[31, 30], 4];
        yield 'E5 030 first' => [[30, 2], 5];
        yield 'E6a 019 skips fame mod' => [[19, 32, 2], 6];
        yield 'E6b 048 skips fame mod' => [[48, 45, 44, 2], 13];
        yield 'E6c 030 hits fame mod' => [[19, 32, 30], 6];
        yield 'E7a 046 with 018' => [[2, [46, 18]], 5];
        yield 'E7b 046 with 018 and 048' => [[2, [46, 18], 48], 10];
        yield 'E7c 046 with 018 and 030' => [[2, [46, 18], 30], 5];
        yield 'E8a sideways counts (044)' => [[2, 44, 29 + self::SIDE], 4];
        yield 'E8b sideways 030 is ignored' => [[2, 29, 30 + self::SIDE], 5];
        yield 'E10 self changes only' => [[2, 17, 1, 16], 10];
        yield 'E11 030 right after 019 (provisional)' => [[19, 30], 3];
        yield 'E12 030 right after 048 (provisional)' => [[48, 30], 3];
    }

    /**
     * @param list<int|array{int, int}> $spec
     */
    #[DataProvider('spec011')]
    public function testSpec011Examples(array $spec, int $expected): void
    {
        self::assertSame($expected, self::total($spec));
    }

    public function testE8HalvedOutputsWithTheCharm(): void
    {
        // E8a: 合計 4 + お守り 1 = 5、半減して 2
        self::assertSame(2, ExploreOutput::decided(self::total([2, 44, 29 + self::SIDE]), 1, true));
        // E8b: 合計 5 + お守り 1 = 6、半減して 3
        self::assertSame(3, ExploreOutput::decided(self::total([2, 29, 30 + self::SIDE]), 1, true));
    }

    public function testE9CharmAddedBeforeHalving(): void
    {
        $total = self::total([3, 4]);
        self::assertSame(4, ExploreOutput::decided($total, ExploreOutput::charmPower(1, 1, 1), false), '1 ラウンド目');
        self::assertSame(5, ExploreOutput::decided($total, ExploreOutput::charmPower(1, 1, 2), false), '2 ラウンド目');
        $halvedTotal = self::total([3, 4 + self::SIDE]);
        self::assertSame(1, ExploreOutput::decided($halvedTotal, ExploreOutput::charmPower(1, 1, 1), true));
        self::assertSame(1, ExploreOutput::decided($halvedTotal, ExploreOutput::charmPower(1, 1, 2), true));
    }

    public function testE8SidewaysCardsHaveZeroPower(): void
    {
        self::assertSame([1, 3, 0], PowerMod::apply(self::prep([2, 44, 29 + self::SIDE]), 2));
        self::assertSame([1, 4, 0], PowerMod::apply(self::prep([2, 29, 30 + self::SIDE]), 2));
    }

    public function testE11AndE12PerCardPowers(): void
    {
        // E11: 030 は 019 の書き換えで名声 3、019 は 030 に 0 にされる
        self::assertSame([0, 3], PowerMod::apply(self::prep([19, 30]), 2));
        self::assertSame([0, 3], PowerMod::apply(self::prep([48, 30]), 2));
    }

    public function testRoundIsNotUsedByTheCardsThemselves(): void
    {
        self::assertSame(self::total([16, 1], false, null, 1), self::total([16, 1], false, null, 5));
    }

    /**
     * 008 のテストケース（探査力変動を持つカード）。[準備場, 期待する合計]
     *
     * @return iterable<string, array{list<int|array{int, int}>, int}>
     */
    public static function cardCases(): iterable
    {
        yield 'TC-016-1' => [[1, 16, 3], 7];
        yield 'TC-016-2' => [[3, 16], 4];
        yield 'TC-016-3' => [[16, 1], 5];
        yield 'TC-017-1' => [[2, 17], 5];
        yield 'TC-017-2' => [[17, 2], 3];
        yield 'TC-017-3' => [[3, 1, 17], 4];
        yield 'TC-018-1' => [[1, 2, 18], 4];
        yield 'TC-018-2' => [[18], 1];
        yield 'TC-018-3' => [[[46, 1], 18], 4];
        yield 'TC-019-1' => [[19, 29], 4];
        yield 'TC-019-2' => [[19, 32], 4];
        yield 'TC-019-3' => [[3, 19], 4];
        yield 'TC-019-4' => [[19, 16, 1], 4];
        yield 'TC-019-5' => [[19, 29, 48], 7];
        yield 'TC-023-1' => [[1, 23], 3];
        yield 'TC-023-2' => [[4, 23], 4];
        yield 'TC-023-3' => [[23], 3];
        yield 'TC-023-4' => [[51, 23], 6];
        yield 'TC-030-1' => [[29, 30], 4];
        yield 'TC-030-2' => [[29, 30, 48], 6];
        yield 'TC-030-3' => [[19, 29, 30], 6];
        yield 'TC-030-4' => [[30, 2], 5];
        yield 'TC-030-5' => [[29, 30 + self::SIDE], 4];
        yield 'TC-031-1' => [[31], 2];
        yield 'TC-031-2' => [[3, 31], 5];
        yield 'TC-031-3' => [[19, 18, 31], 5];
        yield 'TC-031-4' => [[31, 30], 4];
        yield 'TC-032-1' => [[1, 2, 32], 5];
        yield 'TC-033-1' => [[4, 5, 33], 8];
        yield 'TC-040-3' => [[40], 4];
        yield 'TC-040-4' => [[3, 40], 5];
        yield 'TC-042-1 solar before' => [[4, 42], 6];
        yield 'TC-042-1 earth before' => [[1, 42], 3];
        yield 'TC-042-1 first' => [[42], 3];
        yield 'TC-044-1' => [[44], 1];
        yield 'TC-044-2' => [[2, 3, 44], 6];
        yield 'TC-045-1' => [[45, 51], 10];
        yield 'TC-045-2' => [[1, 4, 45], 6];
        yield 'TC-048-1' => [[29, 48], 5];
        yield 'TC-048-2' => [[32, 1, 48], 9];
        yield 'TC-048-3' => [[29, 30, 48], 6];
        yield 'TC-048-4' => [[19, 29, 48], 7];
    }

    /**
     * @param list<int|array{int, int}> $spec
     */
    #[DataProvider('cardCases')]
    public function testCardCases(array $spec, int $expected): void
    {
        self::assertSame($expected, self::total($spec));
    }

    public function testTc022LeftNeighborCount(): void
    {
        self::assertSame(4, self::total([2, 22], false, 3), 'TC-022-1: 2 <= 3');
        self::assertSame(5, self::total([2, 3, 22], false, 2), 'TC-022-2: 3 <= 2 は偽');
        self::assertSame(6, self::total([2, 3, 22], false, 3), 'TC-022-3: 3 <= 3');
        self::assertSame(5, self::total([2, 3, 22], false, null), '左隣が不明なら加算しない');
    }

    public function testTc041HoldsCharm(): void
    {
        self::assertSame(4, self::total([41], true));
        self::assertSame(3, self::total([41], false));
    }

    public function testTc044CountsSidewaysButNotTheAttachedCard(): void
    {
        self::assertSame(6, self::total([2, 3, 44]), 'TC-044-2');
        // TC-044-3: [002, 046（付加カードあり）, 044] は枚数 3
        $powers = PowerMod::apply(self::prep([2, [46, 18], 44]), 2);
        self::assertSame(3, $powers[2]);
    }

    public function testAttachedCardAddsItsPowerAndSymbols(): void
    {
        // 046（2 / 4）+ 018（0 / 1）: 018 の効果は 046 の位置で数える（k = 2）
        self::assertSame([1, 4], PowerMod::apply(self::prep([2, [46, 18]]), 2));
        // 付加カード 004（探査力 2、[太陽系]）: 046 は 2 + 2、直後の 023 は [太陽系] を受けた 046 を見て加算しない
        $powers = PowerMod::apply(self::prep([[46, 4], 23]), 2);
        self::assertSame([4, 2], $powers);
    }

    public function testNothingToCompute(): void
    {
        self::assertSame([], PowerMod::apply([], 1));
    }
}
