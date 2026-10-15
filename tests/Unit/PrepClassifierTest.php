<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Flow\PrepClassifier;
use Bga\Games\MinimumJourney\Flow\PrepPlan;
use PHPUnit\Framework\TestCase;

/**
 * 準備フェイズの純粋な判定（006 §4.6、§4.7、001 §5）。
 */
final class PrepClassifierTest extends TestCase
{
    private const SEATS = [11, 12, 13, 14];

    /**
     * @param array<string, mixed> $override
     * @return array{force_draw: bool, draw_only: list<int>, stop_only: list<int>, late: int|null}
     */
    private static function records(array $override = []): array
    {
        /** @var array{force_draw: bool, draw_only: list<int>, stop_only: list<int>, late: int|null} $merged */
        $merged = array_replace(GlobalValues::emptyNextChoiceRecords(), $override);
        return $merged;
    }

    /**
     * @param array<int, int> $decks 既定の 4 枚を上書きする山札の枚数
     * @return array<int, int>
     */
    private static function deckCounts(array $decks = []): array
    {
        return $decks + [11 => 4, 12 => 4, 13 => 4, 14 => 4];
    }

    public function testEveryoneChoosesWhenNothingSpecial(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [], self::deckCounts(), self::records());

        self::assertSame(self::SEATS, $plan->needChoice);
        self::assertSame([], $plan->fixed);
        self::assertNull($plan->late);
        self::assertSame('needChoice', $plan->transition());
    }

    public function testFinishedPlayersDoNotChoose(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [12 => true, 14 => true], self::deckCounts(), self::records());

        self::assertSame([11, 13], $plan->needChoice);
        self::assertSame([], $plan->cancelled);
    }

    public function testEmptyDeckIsForcedStop(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [], self::deckCounts([13 => 0]), self::records());

        self::assertSame([11, 12, 14], $plan->needChoice);
        self::assertSame([13 => PrepChoice::Stop], $plan->fixed);
        self::assertSame([13 => PrepPlan::REASON_EMPTY_DECK], $plan->reasons);
    }

    public function testEveryoneHasEmptyDeckSkipsToLock(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [], [11 => 0, 12 => 0, 13 => 0, 14 => 0], self::records());

        self::assertSame([], $plan->needChoice);
        self::assertSame('skipToLock', $plan->transition());
        self::assertCount(4, $plan->fixed);
    }

    public function testMemoryAlbumForcesDrawAndSafetyDeviceForcesStop(): void
    {
        $plan = PrepClassifier::plan(
            self::SEATS,
            [],
            self::deckCounts(),
            self::records(['draw_only' => [11], 'stop_only' => [12]]),
        );

        self::assertSame([11 => PrepChoice::Draw, 12 => PrepChoice::Stop], $plan->fixed);
        self::assertSame(
            [11 => PrepPlan::REASON_MEMORY_ALBUM, 12 => PrepPlan::REASON_SAFETY_DEVICE],
            $plan->reasons,
        );
        self::assertSame([13, 14], $plan->needChoice);
    }

    public function testMemoryAlbumCannotDrawFromEmptyDeck(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [], self::deckCounts([11 => 0]), self::records(['draw_only' => [11]]));

        self::assertSame(PrepChoice::Stop, $plan->fixed[11]);
        self::assertSame(PrepPlan::REASON_EMPTY_DECK, $plan->reasons[11]);
    }

    public function testRecordsOnFinishedPlayersAreIgnored(): void
    {
        $plan = PrepClassifier::plan(
            self::SEATS,
            [11 => true],
            self::deckCounts(),
            self::records(['draw_only' => [11], 'late' => 11]),
        );

        self::assertArrayNotHasKey(11, $plan->fixed);
        self::assertNull($plan->late);
        self::assertSame([12, 13, 14], $plan->needChoice);
    }

    public function testTimeStopperIsLateChoiceAndNotInNeedChoice(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [], self::deckCounts(), self::records(['late' => 13]));

        self::assertSame(13, $plan->late);
        self::assertSame([11, 12, 14], $plan->needChoice);
        self::assertSame('needChoice', $plan->transition());
    }

    public function testTimeStopperAloneSkipsToLock(): void
    {
        $plan = PrepClassifier::plan(
            self::SEATS,
            [11 => true, 12 => true, 14 => true],
            self::deckCounts(),
            self::records(['late' => 13]),
        );

        self::assertSame(13, $plan->late);
        self::assertSame([], $plan->needChoice);
        self::assertSame('skipToLock', $plan->transition());
    }

    public function testTimeStopperWithEmptyDeckIsForcedStop(): void
    {
        $plan = PrepClassifier::plan(self::SEATS, [], self::deckCounts([13 => 0]), self::records(['late' => 13]));

        self::assertNull($plan->late);
        self::assertSame(PrepChoice::Stop, $plan->fixed[13]);
    }

    public function testBigBangCancelsFinishedAndForcesDrawForEveryoneWithADeck(): void
    {
        $plan = PrepClassifier::plan(
            self::SEATS,
            [11 => true, 12 => true],
            self::deckCounts([12 => 0, 14 => 0]),
            self::records(['force_draw' => true, 'draw_only' => [13], 'stop_only' => [13], 'late' => 13]),
        );

        // 11 は打ち消される。12 は準備終了のまま山札 0 枚で選択者にならない（打ち消しの対象にも入らない）
        self::assertSame([11], $plan->cancelled);
        self::assertSame(
            [11 => PrepChoice::Draw, 13 => PrepChoice::Draw, 14 => PrepChoice::Stop],
            $plan->fixed,
        );
        self::assertSame(PrepPlan::REASON_BIG_BANG, $plan->reasons[11]);
        self::assertSame(PrepPlan::REASON_EMPTY_DECK, $plan->reasons[14]);
        self::assertSame([], $plan->needChoice);
        self::assertNull($plan->late);
        self::assertSame('skipToLock', $plan->transition());
    }

    public function testRevealAllStop(): void
    {
        $plan = PrepClassifier::reveal(
            self::SEATS,
            [11 => PrepChoice::Stop, 12 => PrepChoice::Stop, 13 => PrepChoice::Stop],
            self::deckCounts(),
        );

        self::assertSame('allStop', $plan->transition);
        self::assertSame([], $plan->drawers);
        self::assertFalse($plan->stoppersDrawFacedown);
    }

    public function testRevealSoloDrawMakesStoppersDrawFacedown(): void
    {
        $plan = PrepClassifier::reveal(
            self::SEATS,
            [11 => PrepChoice::Stop, 12 => PrepChoice::Draw, 13 => PrepChoice::Stop],
            self::deckCounts(),
        );

        self::assertSame('soloDraw', $plan->transition);
        self::assertSame([12], $plan->drawers);
        self::assertSame([11, 13], $plan->stoppers);
        self::assertTrue($plan->stoppersDrawFacedown);
    }

    public function testRevealTwoDrawsResolves(): void
    {
        $plan = PrepClassifier::reveal(
            self::SEATS,
            [11 => PrepChoice::Draw, 12 => PrepChoice::Draw, 13 => PrepChoice::Stop],
            self::deckCounts(),
        );

        self::assertSame('resolve', $plan->transition);
        self::assertSame([11, 12], $plan->drawers);
    }

    public function testRevealCountsOnlyDrawsThatCanBeMade(): void
    {
        // 山札 0 枚の人のドローは引けないのでストップ扱い。実質 1 人だけのドロー（001 E3）
        $plan = PrepClassifier::reveal(
            self::SEATS,
            [11 => PrepChoice::Draw, 12 => PrepChoice::Draw],
            self::deckCounts([12 => 0]),
        );

        self::assertSame('soloDraw', $plan->transition);
        self::assertSame([11], $plan->drawers);
        self::assertSame([12], $plan->stoppers);
    }

    public function testRevealIgnoresPlayersWithoutAChoice(): void
    {
        $plan = PrepClassifier::reveal(self::SEATS, [11 => null], self::deckCounts());

        self::assertSame('allStop', $plan->transition);
        self::assertSame([], $plan->stoppers);
    }
}
