<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Flow\ExplorePhase;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\ExploreStepResult;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Flow\PrepPhase;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 探査フェイズ（状態 40〜48。ExplorePhase）と探査出力の計算を、セットアップ後の実 DB で動かす。
 */
final class ExplorePhaseTest extends DatabaseTestCase
{
    private const PLAYER_IDS = [2310001, 2310002, 2310003, 2310004];

    /** @var list<int> */
    private array $ids = [];
    private ExplorePhase $phase;
    private ExploreOutputCalculator $calculator;

    /**
     * @return iterable<string, array{int}>
     */
    public static function playerCounts(): iterable
    {
        yield '3 players' => [3];
        yield '4 players' => [4];
    }

    private function start(int $count, int $round = 2): void
    {
        $this->ids = array_slice(self::PLAYER_IDS, 0, $count);
        $this->addPlayers($this->ids);
        (new GameSetup($this->cards, $this->globals, $this->rand))->run($this->ids, null);
        $this->calculator = new ExploreOutputCalculator($this->cards, $this->globals, fn(): array => $this->ids);
        $name = static fn(int $id): string => 'P' . $id;
        $this->phase = new ExplorePhase($this->cards, $this->players, $this->globals, $this->calculator, $name);
        $this->globals->setRoundNo($round);
    }

    private function charm(): Card
    {
        return $this->cards->list(Zone::Charm)[0];
    }

    private function giveCharm(int $playerId): void
    {
        $this->cards->move($this->charm(), Zone::Charm, $playerId);
    }

    /**
     * 山札の上から $n 枚を準備場へ。印字の探査力の合計を返す。
     */
    private function prepare(int $playerId, int $n): int
    {
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $card = $this->cards->drawTopToPrep($playerId);
            self::assertNotNull($card);
            $sum += CardDefinitions::get(sprintf('%03d', $card->cardNo))->power ?? 0;
        }
        return $sum;
    }

    /**
     * @param list<Notification> $notifications
     * @return list<Notification>
     */
    private static function named(array $notifications, string $name): array
    {
        return array_values(array_filter($notifications, static fn(Notification $n): bool => $n->name === $name));
    }

    /**
     * 状態 48 相当（手番インデックスを進め、手番中の一時データを消す）。
     */
    private function endTurn(): void
    {
        $this->globals->setExploreIndex($this->globals->exploreIndex() + 1);
        $this->globals->setExploreState(null);
    }

    /**
     * 40 を実行し、全員の探査出力を $outputs に固定する（探査の判定を探査力の組み合わせから切り離す）。
     *
     * @param array<int, int> $outputs プレイヤー ID => 探査出力
     */
    private function startWithOutputs(array $outputs): ExploreStepResult
    {
        $result = $this->phase->start($this->ids);
        foreach ($outputs as $id => $output) {
            $this->players->setExploreOutput($id, $output);
        }
        return $result;
    }

    /**
     * 全員に同じ探査出力を固定する。
     *
     * @return array<int, int>
     */
    private function everyone(int $output): array
    {
        return array_fill_keys($this->ids, $output);
    }

    #[DataProvider('playerCounts')]
    public function testOutputNowSumsPrintedPowerOfPrepAndTheCharmWithoutHalving(int $count): void
    {
        $this->start($count, 2);
        [$a, $b, $c] = $this->ids;
        $this->giveCharm($b);
        $sumA = $this->prepare($a, 3);
        $sumB = $this->prepare($b, 2);

        self::assertSame($sumA, $this->calculator->outputNow($a));
        self::assertSame($sumB + 1, $this->calculator->outputNow($b), '2 ラウンド目の航路安全は +1');
        self::assertSame(0, $this->calculator->outputNow($c), '準備場が空');

        // 横向きと半減: 探査力は無視され、準備中の値には半減を含めない（確定時にだけ半減する）
        $first = $this->cards->list(Zone::Prep, $b)[0];
        $this->cards->setSideways($first, true);
        $power = CardDefinitions::get(sprintf('%03d', $first->cardNo))->power ?? 0;
        self::assertSame($sumB - $power + 1, $this->calculator->outputNow($b));
        self::assertSame(intdiv($sumB - $power + 1, 2), $this->calculator->decided($b, true));
        self::assertSame($sumB - $power + 1, $this->calculator->decided($b, false));
    }

    public function testCharmPowerIsZeroInRoundOneAndForTheOtherSide(): void
    {
        $this->start(3, 1);
        $this->giveCharm($this->ids[0]);
        self::assertSame(0, $this->calculator->charmPower($this->ids[0]));
        self::assertSame(0, $this->calculator->outputNow($this->ids[0]));

        $this->globals->setRoundNo(2);
        self::assertSame(1, $this->calculator->charmPower($this->ids[0]));
        self::assertSame(0, $this->calculator->charmPower($this->ids[1]), '所持者だけに加算される');

        // 厄除けのお守り（058）は常に 0
        $charm = $this->charm();
        $this->db->execute('UPDATE `card` SET `card_no` = 58 WHERE `card_id` = ' . $charm->id);
        self::assertSame(0, $this->calculator->charmPower($this->ids[0]));
    }

    #[DataProvider('playerCounts')]
    public function testStartFixesOutputsHalvesAndOrdersByPrepCountThenOutput(int $count): void
    {
        $this->start($count, 2);
        [$a, $b, $c] = $this->ids;
        $this->giveCharm($c);
        $this->prepare($a, 3);
        $this->prepare($b, 1);
        $this->prepare($c, 2);
        if ($count === 4) {
            $this->prepare($this->ids[3], 4);
        }
        // b は 1 人だけのドローで横向き・半減
        $this->players->setHalved($b, true);
        $this->cards->setSideways($this->cards->list(Zone::Prep, $b)[0], true);

        $result = $this->phase->start($this->ids);

        self::assertSame('start', $result->transition);
        self::assertSame(GlobalValues::PHASE_EXPLORE, $this->globals->phase());
        self::assertSame(0, $this->players->exploreOutput($b), '横向き 1 枚の合計 0 → 半減 0');
        self::assertSame($this->calculator->decided($a, false), $this->players->exploreOutput($a));
        self::assertSame($this->calculator->decided($c, false), $this->players->exploreOutput($c));
        $order = $this->globals->exploreOrder();
        self::assertSame($b, $order[0], '準備枚数 1 が最初');
        self::assertSame($c, $order[1], '準備枚数 2 が次');
        self::assertSame($a, $order[2], '準備枚数 3');
        if ($count === 4) {
            self::assertSame($this->ids[3], $order[3], '準備枚数 4');
        }
        self::assertSame(0, $this->globals->exploreIndex());

        self::assertCount(1, self::named($result->notifications, 'exploreStarted'));
        self::assertCount($count, self::named($result->notifications, 'exploreOutputDecided'));
        $decided = self::named($result->notifications, 'exploreOutputDecided');
        self::assertTrue($decided[1]->args['halved']);
        $orderNote = self::named($result->notifications, 'exploreOrderDecided')[0];
        self::assertSame($order, $orderNote->args['order']);
        self::assertSame(implode(', ', array_map(static fn(int $id): string => 'P' . $id, $order)), $orderNote->args['order_names']);
    }

    #[DataProvider('playerCounts')]
    public function testEveryoneStoppedStartsFromTheCharmHolderClockwise(int $count): void
    {
        // 全員準備場が空・出力 0（1 ラウンド目はお守りも 0）→ お守り所持者から時計回り
        $this->start($count, 1);
        $this->giveCharm($this->ids[1]);

        $this->phase->start($this->ids);

        $expected = [$this->ids[1], $this->ids[2]];
        if ($count === 4) {
            $expected[] = $this->ids[3];
        }
        $expected[] = $this->ids[0];
        self::assertSame($expected, $this->globals->exploreOrder());
    }

    #[DataProvider('playerCounts')]
    public function testCharmHolderWithOutputOneGoesLastWhenEverythingElseTies(int $count): void
    {
        // 2 ラウンド目以降、全員空でも所持者だけ出力 1（E4）なので、所持者が最後になる
        $this->start($count, 2);
        $this->giveCharm($this->ids[0]);

        $this->phase->start($this->ids);

        $order = $this->globals->exploreOrder();
        self::assertSame($this->ids[0], $order[$count - 1]);
        self::assertSame(1, $this->players->exploreOutput($this->ids[0]));
        self::assertSame($this->ids[1], $order[0], '所持者から時計回りで近い順（所持者の次の席から）');
    }

    #[DataProvider('playerCounts')]
    public function testTurnLoopFollowsTheOrderAndEnds(int $count): void
    {
        $this->start($count, 2);
        $this->giveCharm($this->ids[2]);
        $this->startWithOutputs([]);
        $order = $this->globals->exploreOrder();

        $seen = [];
        while (true) {
            $turn = $this->phase->beginTurn();
            if ($turn->transition === 'allDone') {
                break;
            }
            self::assertSame('choose', $turn->transition);
            self::assertNotNull($turn->playerId);
            $seen[] = $turn->playerId;
            $note = self::named($turn->notifications, 'exploreTurnStarted')[0];
            self::assertSame(count($seen) - 1, $note->args['index']);
            self::assertSame($turn->playerId, $this->globals->exploreState()['player_id'] ?? null);
            $this->endTurn();
        }

        self::assertSame($order, $seen);
        self::assertNull($this->globals->exploreState());
    }

    #[DataProvider('playerCounts')]
    public function testNothingToExploreAutoPassesAndIsLogged(int $count): void
    {
        $this->start($count);
        $this->startWithOutputs($this->everyone(1));
        $this->phase->beginTurn();

        foreach ([$this->ids[0], $this->ids[1]] as $id) {
            $result = $this->phase->enterChoose($id);

            self::assertSame('passed', $result->transition, '最小コストは 2');
            $note = self::named($result->notifications, 'explorePassed')[0];
            self::assertTrue($note->args['auto']);
            self::assertSame($id, $note->args['player_id']);
        }
    }

    #[DataProvider('playerCounts')]
    public function testWhenThereIsATargetTheStateIsEnteredAndPassIsAvailable(int $count): void
    {
        $this->start($count);
        $this->startWithOutputs($this->everyone(2));
        $first = $this->globals->exploreOrder()[0];
        $this->phase->beginTurn();

        $enter = $this->phase->enterChoose($first);
        self::assertNull($enter->transition);
        self::assertSame([], $enter->notifications);

        $pass = $this->phase->pass($first);
        self::assertSame('passed', $pass->transition);
        self::assertFalse(self::named($pass->notifications, 'explorePassed')[0]->args['auto']);
        self::assertSame(0, $this->cards->count(Zone::Acquired, $first));
    }

    #[DataProvider('playerCounts')]
    public function testExploreARowCardMovesItToAcquiredAndLeavesTheSlotEmpty(int $count): void
    {
        $this->start($count);
        $this->startWithOutputs($this->everyone(4));
        $first = $this->globals->exploreOrder()[0];
        $second = $this->globals->exploreOrder()[1];
        $this->phase->beginTurn();

        $targets = $this->phase->targets($first);
        self::assertNotSame([], $targets['rowTargets']);
        foreach ($targets['rowTargets'] as $t) {
            self::assertLessThanOrEqual(4, $t['effectiveCost']);
        }
        $deckCountBefore = $this->cards->count(Zone::ExploreDeck, 2);
        $target = $targets['rowTargets'][0];

        $result = $this->phase->explore($first, ExplorePhase::SOURCE_ROW, $target['cardId'], 0);

        self::assertSame('acquired', $result->transition);
        self::assertSame([$target['cardId']], $this->ids(Zone::Acquired, $first));
        self::assertSame($deckCountBefore, $this->cards->count(Zone::ExploreDeck, 2), '列は帰還フェイズまで補充しない');
        $note = self::named($result->notifications, 'cardAcquired')[0];
        self::assertSame($target['cardId'], $note->args['card']['id']);
        self::assertSame('row', $note->args['from']);
        self::assertFalse($note->args['free']);
        $counters = self::named($result->notifications, 'countersUpdate')[0];
        self::assertSame(1, $counters->args['players'][$first]['acquiredCount']);
        self::assertArrayNotHasKey('piles', $counters->args);
        $state = $this->globals->exploreState();
        self::assertSame(1, $state['explored'] ?? null);
        self::assertSame($target['effectiveCost'], $state['sum_cost'] ?? null);

        // 後続のプレイヤーには、獲得されたカードは候補に出ない
        $this->endTurn();
        $this->phase->beginTurn();
        $ids = array_column($this->phase->targets($second)['rowTargets'], 'cardId');
        self::assertNotContains($target['cardId'], $ids);
        self::assertSame([], self::named($result->notifications, 'gameEndTriggered'));
    }

    #[DataProvider('playerCounts')]
    public function testExploreDeckTopRevealsAndAcquiresTheTopCardEvenIfRowHasTargets(int $count): void
    {
        $this->start($count);
        $this->startWithOutputs($this->everyone(6));
        $first = $this->globals->exploreOrder()[0];
        $this->phase->beginTurn();
        $top = $this->cards->peekTop(Zone::ExploreDeck, 6, 1)[0];
        $before = $this->cards->count(Zone::ExploreDeck, 6);

        // 公開前の args に券面や ID を入れない
        $args = $this->phase->targets($first);
        self::assertStringNotContainsString((string) $top->id, (string) json_encode($args['deckTargets']));
        self::assertContains(6, array_column($args['deckTargets'], 'cost'));

        $result = $this->phase->explore($first, ExplorePhase::SOURCE_DECK, 0, 6);

        self::assertSame('acquired', $result->transition);
        self::assertSame([$top->id], $this->ids(Zone::Acquired, $first));
        self::assertSame($before - 1, $this->cards->count(Zone::ExploreDeck, 6));
        $note = self::named($result->notifications, 'cardAcquired')[0];
        self::assertSame('deck', $note->args['from']);
        self::assertSame(6, $note->args['cost']);
        self::assertSame(['id' => $top->id, 'cardNo' => $top->cardNo, 'art' => $top->art], $note->args['card']);
        $counters = self::named($result->notifications, 'countersUpdate')[0];
        self::assertSame($before - 1, $counters->args['piles'][6]);
        self::assertFalse($this->globals->gameEndTriggered(), 'コスト 6 の山札では終了フラグは立たない');
    }

    #[DataProvider('playerCounts')]
    public function testDeckTopWithEmptyRowIsStillAvailable(int $count): void
    {
        $this->start($count);
        $this->startWithOutputs($this->everyone(2));
        $first = $this->globals->exploreOrder()[0];
        $this->phase->beginTurn();
        foreach ($this->cards->list(Zone::Row, 2) as $card) {
            $this->cards->move($card, Zone::Removed);
        }

        $targets = $this->phase->targets($first);

        self::assertSame([], $targets['rowTargets']);
        self::assertSame([2], array_column($targets['deckTargets'], 'cost'));
        self::assertNull($this->phase->enterChoose($first)->transition, '山札の上があるので自動パスしない');
    }

    #[DataProvider('playerCounts')]
    public function testAcquiringTheUnknownBandRaisesTheEndFlagButTheRoundContinues(int $count): void
    {
        $this->start($count);
        $this->startWithOutputs($this->everyone(10));
        [$first, $second] = $this->globals->exploreOrder();
        $this->phase->beginTurn();
        $unknown = $this->cards->list(Zone::Unknown)[0];
        $targets = $this->phase->targets($first);
        self::assertSame($unknown->id, $targets['unknownTarget']['cardId'] ?? null);

        $result = $this->phase->explore($first, ExplorePhase::SOURCE_UNKNOWN, $unknown->id, 0);

        self::assertSame([$unknown->id], $this->ids(Zone::Acquired, $first));
        self::assertSame(0, $this->cards->count(Zone::Unknown));
        self::assertTrue($this->globals->gameEndTriggered());
        self::assertSame(GlobalValues::END_UNKNOWN, $this->globals->endReason());
        self::assertSame($first, $this->globals->unknownAcquirer());
        $note = self::named($result->notifications, 'gameEndTriggered')[0];
        self::assertSame('unknown', $note->args['reason']);
        self::assertSame($first, $note->args['player_id']);
        self::assertSame('unknown', self::named($result->notifications, 'cardAcquired')[0]->args['from']);

        // 残りのプレイヤーも手番を行う（未確認帯域は他者には対象外）
        $this->endTurn();
        $turn = $this->phase->beginTurn();
        self::assertSame('choose', $turn->transition);
        self::assertSame($second, $turn->playerId);
        self::assertNull($this->phase->targets($second)['unknownTarget']);
    }

    public function testEmptyingThePile8RaisesTheEndFlagOnlyOnTheLastCardAndKeepsTheFirstReason(): void
    {
        $this->start(3);
        $this->startWithOutputs($this->everyone(8));
        $first = $this->globals->exploreOrder()[0];
        $this->phase->beginTurn();
        $pile = $this->cards->list(Zone::ExploreDeck, 8);
        foreach (array_slice($pile, 1) as $card) { // 最後の 1 枚を残す
            $this->cards->move($card, Zone::Removed);
        }
        $last = $this->cards->peekTop(Zone::ExploreDeck, 8, 1)[0];
        self::assertFalse($this->globals->gameEndTriggered());

        $result = $this->phase->explore($first, ExplorePhase::SOURCE_DECK, 0, 8);

        self::assertSame([$last->id], $this->ids(Zone::Acquired, $first));
        self::assertTrue($this->globals->gameEndTriggered());
        self::assertSame(GlobalValues::END_PILE8, $this->globals->endReason());
        self::assertNull($this->globals->unknownAcquirer());
        self::assertSame('pile8', self::named($result->notifications, 'gameEndTriggered')[0]->args['reason']);

        // 山が空になったので次の人は 8 の山札を選べない
        $this->endTurn();
        $this->phase->beginTurn();
        $second = $this->globals->exploreOrder()[1];
        self::assertNotContains(8, array_column($this->phase->targets($second)['deckTargets'], 'cost'));

        // 先に起きた理由を残す: 未確認帯域を獲得しても end_reason は pile8 のまま、通知も出ない
        $unknown = $this->cards->list(Zone::Unknown)[0];
        $this->players->setExploreOutput($second, 10);
        $second2 = $this->phase->explore($second, ExplorePhase::SOURCE_UNKNOWN, $unknown->id, 0);
        self::assertSame(GlobalValues::END_PILE8, $this->globals->endReason());
        self::assertSame($second, $this->globals->unknownAcquirer(), '獲得者は記録する');
        self::assertSame([], self::named($second2->notifications, 'gameEndTriggered'));
    }

    public function testInvalidExploresAreRejectedAndChangeNothing(): void
    {
        $this->start(3);
        $this->startWithOutputs($this->everyone(3));
        [$first, $second] = $this->globals->exploreOrder();
        $this->phase->beginTurn();
        $expensive = $this->cards->list(Zone::Row, 6)[0];
        $cheap = $this->cards->list(Zone::Row, 2)[0];
        $unknown = $this->cards->list(Zone::Unknown)[0];

        $attempts = [
            'cost above output (row)' => [$first, 'row', $expensive->id, 0, ExploreRuleViolation::NOT_A_TARGET],
            'cost above output (deck)' => [$first, 'deck', 0, 4, ExploreRuleViolation::NOT_A_TARGET],
            'unknown band' => [$first, 'unknown', $unknown->id, 0, ExploreRuleViolation::NOT_A_TARGET],
            'not a row card' => [$first, 'row', $this->cards->peekTop(Zone::ExploreDeck, 2, 1)[0]->id, 0, ExploreRuleViolation::NOT_A_TARGET],
            'unknown source' => [$first, 'hand', $cheap->id, 0, ExploreRuleViolation::NOT_A_TARGET],
            'another player' => [$second, 'row', $cheap->id, 0, ExploreRuleViolation::NOT_YOUR_TURN],
        ];
        foreach ($attempts as $label => [$player, $source, $cardId, $cost, $reason]) {
            try {
                $this->phase->explore($player, $source, $cardId, $cost);
                self::fail("should be rejected: $label");
            } catch (ExploreRuleViolation $e) {
                self::assertSame($reason, $e->reason, $label);
            }
        }
        self::assertSame(0, $this->cards->count(Zone::Acquired, $first));

        // 1 手番に探査できるのは 1 枚（追加探査は I-11）
        $this->phase->explore($first, ExplorePhase::SOURCE_ROW, $cheap->id, 0);
        $this->expectException(ExploreRuleViolation::class);
        $this->phase->explore($first, ExplorePhase::SOURCE_DECK, 0, 2);
    }

    #[DataProvider('playerCounts')]
    public function testPrepThenExploreFullCycle(int $count): void
    {
        $this->start($count, 2);
        $this->giveCharm($this->ids[2]);
        $prep = new PrepPhase($this->cards, $this->players, $this->globals, static fn(int $id): string => 'P' . $id, $this->calculator);
        [$a, $b] = $this->ids;

        // a だけがドロー（横向き・半減）、残りはストップ
        $prep->begin($this->ids);
        foreach ($this->globals->prepSelectors() as $id) {
            $prep->select($id, $id === $a ? PrepChoice::Draw : PrepChoice::Stop);
        }
        $prep->lock($this->ids);
        $reveal = $prep->reveal($this->ids);
        self::assertSame('soloDraw', $reveal->transition);

        // N33 countersUpdate に outputNow（お守りを含み半減は含めない）が入る
        $counters = self::named($reveal->notifications, 'countersUpdate')[0];
        foreach ($this->ids as $id) {
            self::assertSame($this->calculator->outputNow($id), $counters->args['players'][$id]['outputNow'] ?? null);
        }
        self::assertSame(0, $counters->args['players'][$a]['outputNow'], '横向きの探査力は無視する');
        self::assertSame(1, $counters->args['players'][$this->ids[2]]['outputNow'], '2 ラウンド目の航路安全 +1');

        $start = $this->phase->start($this->ids);

        self::assertSame('start', $start->transition);
        self::assertSame(0, $this->players->exploreOutput($a));
        self::assertSame(1, $this->players->exploreOutput($this->ids[2]));
        self::assertSame(0, $this->players->exploreOutput($b));
        // 準備枚数: a だけ 1 枚、他は 0 → a が最後、その手前に出力 1 のお守り所持者
        $order = $this->globals->exploreOrder();
        self::assertSame($a, $order[$count - 1]);
        self::assertSame($this->ids[2], $order[$count - 2]);
    }

    public function testBoardViewShowsOutputNowAndTheFixedOutput(): void
    {
        $this->start(3, 2);
        $this->giveCharm($this->ids[0]);
        $this->prepare($this->ids[0], 1);
        $view = new \Bga\Games\MinimumJourney\Data\BoardView($this->cards, $this->globals, $this->players, $this->calculator);

        $board = $view->build($this->ids);
        self::assertSame($this->calculator->outputNow($this->ids[0]), $board['players'][$this->ids[0]]['outputNow']);
        self::assertNull($board['players'][$this->ids[0]]['output']);
        self::assertSame(1, $board['players'][$this->ids[0]]['prepCount']);

        $this->phase->start($this->ids);
        $board = $view->build($this->ids);
        self::assertSame($this->players->exploreOutput($this->ids[0]), $board['players'][$this->ids[0]]['output']);
    }

    /**
     * 指定の番号のカード 1 枚を、プレイヤーの準備場の末尾へ置く（置いたカードを返す）。
     */
    private function put(int $playerId, int $cardNo): Card
    {
        $card = $this->cards->findByCardNo($cardNo)[0];
        $this->cards->move($card, Zone::Prep, $playerId);
        return $this->cards->get($card->id);
    }

    #[DataProvider('playerCounts')]
    public function testPowerModChainIsReflectedInOutputNowAndTheFixedOutput(int $count): void
    {
        $this->start($count, 2);
        [$a] = $this->ids;
        // 011 E1: 029、030、048 → 048 が全てを名声に書き換え、030 が 029 を 0 にする。合計 6
        foreach ([29, 30, 48] as $cardNo) {
            $this->put($a, $cardNo);
        }
        self::assertSame(6, $this->calculator->outputNow($a));
        self::assertSame(3, $this->calculator->decided($a, true), '半減は合計の後');

        $this->phase->start($this->ids);
        self::assertSame(6, $this->players->exploreOutput($a));
    }

    #[DataProvider('playerCounts')]
    public function testPowerModUsesLeftNeighborCharmHolderAndTheAttachedCard(int $count): void
    {
        $this->start($count, 2);
        $ids = $this->ids;
        $last = $ids[$count - 1];
        $this->giveCharm($ids[0]);

        // 先頭の席: 022（左隣は 2 番目の席で 2 枚 → 2 <= 2 で +1）、041（お守りを保持 +1）、お守り +1
        $this->put($ids[0], 22);
        $this->put($ids[0], 41);
        $this->put($ids[1], 1);
        $this->put($ids[1], 2);
        self::assertSame(3 + 4 + 1, $this->calculator->outputNow($ids[0]));

        // 最後の席の左隣は先頭の席（2 枚）: 022 は 1 枚なので +1
        $this->put($last, 22);
        self::assertSame(3, $this->calculator->outputNow($last));

        // 001、002 の後ろに 046 を置き、018 を付加: 046 は 3 枚目なので 2 + 0 + 3（付加カードは枚数に数えない）
        $host = $this->put($ids[1], 46);
        $this->cards->attach($host, $this->cards->findByCardNo(18)[0]);
        self::assertSame(0 + 1 + 5, $this->calculator->outputNow($ids[1]));
    }
}
