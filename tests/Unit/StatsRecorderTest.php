<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Unit;

use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Stats\StatsRecorder;
use MinimumJourneyTests\Support\ArrayStatStore;
use PHPUnit\Framework\TestCase;

/**
 * 統計の更新規則（007 §12）。
 */
final class StatsRecorderTest extends TestCase
{
    private ArrayStatStore $store;
    private StatsRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = new ArrayStatStore();
        $this->recorder = new StatsRecorder($this->store);
        $this->recorder->init();
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function n(string $name, array $args): Notification
    {
        return new Notification(null, $name, '', $args);
    }

    public function testChoicesAreCountedAndForcedStopsByEmptyDeckAreNotStops(): void
    {
        $this->recorder->recordNotifications([
            self::n('choiceRevealed', ['player_id' => 1, 'choice' => 'draw', 'reason' => null]),
            self::n('choiceRevealed', ['player_id' => 2, 'choice' => 'stop', 'reason' => null]),
            self::n('choiceRevealed', ['player_id' => 3, 'choice' => 'stop', 'reason' => 'emptyDeck']),
            self::n('choiceRevealed', ['player_id' => 1, 'choice' => 'draw', 'reason' => 'bigBang']),
        ], 1);

        self::assertSame(2, $this->store->player['draw_count'][1]);
        self::assertSame(1, $this->store->player['stop_count'][2]);
        self::assertArrayNotHasKey(3, $this->store->player['stop_count']);
        self::assertSame(1, $this->store->player['forced_choice_count'][3]);
        self::assertSame(1, $this->store->player['forced_choice_count'][1]);
    }

    public function testPrepRevealCountsStepsAndBranches(): void
    {
        $withChoice = [self::n('choiceRevealed', ['player_id' => 1, 'choice' => 'stop'])];
        $this->recorder->recordPrepReveal('soloDraw', $withChoice);
        $this->recorder->recordPrepReveal('allStop', $withChoice);
        $this->recorder->recordPrepReveal('resolve', $withChoice);
        $this->recorder->recordPrepReveal('resolve', []);

        self::assertSame(3, $this->store->table['prep_steps']);
        self::assertSame(1, $this->store->table['solo_draw_rounds']);
        self::assertSame(1, $this->store->table['all_stop_rounds']);
    }

    public function testSoloDrawAcquireRemovePassAndFirstTurn(): void
    {
        $this->recorder->recordNotifications([
            self::n('cardSideways', ['player_id' => 2]),
            self::n('cardAcquired', ['player_id' => 1, 'card_no' => 10]),
            self::n('cardAcquired', ['player_id' => 1, 'card_no' => 56]),
            self::n('cardsRemoved', ['player_id' => 1, 'attached_card' => null]),
            self::n('cardsRemoved', ['player_id' => 1, 'attached_card' => ['id' => 5]]),
            self::n('explorePassed', ['player_id' => 3, 'auto' => true]),
            self::n('exploreOrderDecided', ['order' => [3, 1, 2]]),
            self::n('finalScore', ['player_id' => 1, 'deck_size' => 14]),
        ], 4);

        self::assertSame(1, $this->store->player['solo_draw_count'][2]);
        self::assertSame(2, $this->store->player['cards_acquired'][1]);
        self::assertTrue($this->store->player['unknown_acquired'][1]);
        self::assertSame(3, $this->store->player['cards_removed'][1]);
        self::assertSame(1, $this->store->player['pass_count'][3]);
        self::assertSame(1, $this->store->player['first_turn_count'][3]);
        self::assertSame(14, $this->store->player['deck_size_final'][1]);
    }

    public function testOutputKeepsMaxAndRunningAverage(): void
    {
        foreach ([[1, 4], [2, 8], [3, 3]] as [$round, $output]) {
            $this->recorder->recordNotifications([self::n('exploreOutputDecided', ['player_id' => 1, 'output' => $output])], $round);
        }

        self::assertSame(8, $this->store->player['max_output'][1]);
        self::assertEqualsWithDelta(5.0, $this->store->player['average_output'][1], 0.0001);
    }

    public function testEndReasonRoundsAndCharm(): void
    {
        $this->recorder->recordNotifications([self::n('gameEndTriggered', ['reason' => 'pile8'])], 3);
        self::assertSame(StatsRecorder::END_REASON_PILE8, $this->store->table['end_reason']);
        $this->recorder->recordNotifications([self::n('gameEndTriggered', ['reason' => 'unknown'])], 3);
        self::assertSame(StatsRecorder::END_REASON_UNKNOWN, $this->store->table['end_reason']);

        $this->recorder->recordRoundFinished();
        $this->recorder->recordRoundFinished();
        $this->recorder->recordCharmHolder(2);
        self::assertSame(2, $this->store->table['rounds_played']);
        self::assertSame(1, $this->store->player['charm_rounds'][2]);
    }
}
