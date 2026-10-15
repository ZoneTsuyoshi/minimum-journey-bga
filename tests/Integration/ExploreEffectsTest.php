<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Flow\ExplorePhase;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\Notification;
use MinimumJourneyTests\Support\DatabaseTestCase;

/**
 * 探査時（011・013）・探査中（補正、禁止、012 の追加探査）の効果を、実 DB で動かす（008 §4、TC-011・013・012・X・025・026・007）。
 */
final class ExploreEffectsTest extends DatabaseTestCase
{
    private const A = 2320001;
    private const B = 2320002;
    private const C = 2320003;

    private ExplorePhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        $ids = [self::A, self::B, self::C];
        $this->addPlayers($ids);
        $this->globals->setRoundNo(2);
        $this->globals->setPhase(GlobalValues::PHASE_EXPLORE);
        $this->phase = new ExplorePhase(
            $this->cards,
            $this->players,
            $this->globals,
            new ExploreOutputCalculator($this->cards, $this->globals, fn(): array => $ids),
            static fn(int $id): string => 'P' . $id,
        );
    }

    private function mk(int $no, Zone $zone, int $arg, int $order = 0): Card
    {
        return $this->cards->create($no, $no <= 3 ? 1 : 0, $zone, $arg, $order);
    }

    private function prep(int $player, int ...$nos): void
    {
        foreach ($nos as $i => $no) {
            $this->mk($no, Zone::Prep, $player, $i);
        }
    }

    /**
     * 探査手番の順序と探査出力を固定し、先頭の手番を始める。
     *
     * @param list<int> $order
     * @param array<int, int> $outputs
     */
    private function begin(array $order, array $outputs): string
    {
        $this->globals->setExploreOrder($order);
        $this->globals->setExploreIndex(0);
        foreach ($outputs as $id => $output) {
            $this->players->setExploreOutput($id, $output);
        }
        return (string) $this->phase->beginTurn()->transition;
    }

    private function nextTurn(): string
    {
        $this->globals->setExploreIndex($this->globals->exploreIndex() + 1);
        $this->globals->setExploreState(null);
        return (string) $this->phase->beginTurn()->transition;
    }

    /**
     * @return array<int, int> カード ID => 実効コスト
     */
    private function rowCosts(int $player): array
    {
        $costs = [];
        foreach ($this->phase->targets($player)['rowTargets'] as $t) {
            $costs[$t['cardId']] = $t['effectiveCost'];
        }
        ksort($costs);
        return $costs;
    }

    /**
     * @param list<Notification> $notes
     * @return list<Notification>
     */
    private static function named(array $notes, string $name): array
    {
        return array_values(array_filter($notes, static fn(Notification $n): bool => $n->name === $name));
    }

    public function testTcX1StackedModifiersOnRowPilesAndUnknownBand(): void
    {
        $this->prep(self::A, 6, 21, 38);
        $r036 = $this->mk(36, Zone::Row, 6);
        $r046 = $this->mk(46, Zone::Row, 8, 0);
        $r019 = $this->mk(19, Zone::Row, 4);
        $r051 = $this->mk(51, Zone::Row, 8, 1);
        $r004 = $this->mk(4, Zone::Row, 2);
        $this->mk(56, Zone::Unknown, 0);
        foreach ([2, 4, 6, 8] as $cost) {
            $this->mk(5, Zone::ExploreDeck, $cost);
        }
        $this->begin([self::A, self::B, self::C], [self::A => 99, self::B => 99, self::C => 99]);

        $expected = [$r036->id => 4, $r046->id => 6, $r019->id => 2, $r051->id => 5, $r004->id => 1];
        ksort($expected);
        self::assertSame($expected, $this->rowCosts(self::A));
        $targets = $this->phase->targets(self::A);
        self::assertSame(9, $targets['unknownTarget']['effectiveCost'] ?? null);
        self::assertSame([1, 2, 4, 6], array_column($targets['deckTargets'], 'effectiveCost'));
    }

    public function testModifiersOnlyApplyInTheOwnTurnAndWhileFaceUpInThePrepArea(): void
    {
        $this->prep(self::A, 6);
        $this->mk(1, Zone::Prep, self::B, 0);
        $r019 = $this->mk(19, Zone::Row, 4);
        $this->begin([self::B, self::A, self::C], [self::A => 4, self::B => 4, self::C => 4]);
        self::assertSame([$r019->id => 4], $this->rowCosts(self::B), '他人の準備場の 006 は入らない');

        $this->nextTurn();
        self::assertSame([$r019->id => 3], $this->rowCosts(self::A));
        $this->cards->setSideways($this->cards->list(Zone::Prep, self::A)[0], true);
        self::assertSame([$r019->id => 4], $this->rowCosts(self::A), '横向きの 006 は無視される');
    }

    public function testRoverProbeAppliesOnlyToTheMinimumOutputAndTies(): void
    {
        $this->prep(self::A, 15);
        $this->prep(self::B, 15);
        $r = $this->mk(19, Zone::Row, 4);
        $this->begin([self::A, self::B, self::C], [self::A => 4, self::B => 5, self::C => 4]);
        self::assertSame([$r->id => 2], $this->rowCosts(self::A), '同値も最小');
        $this->nextTurn();
        self::assertSame([$r->id => 4], $this->rowCosts(self::B), '最小でなければ補正なし');
    }

    public function testSpaceFoodAppliesToTheLastTurnAndRoughMapToTheFirst(): void
    {
        $this->prep(self::A, 38);
        $this->prep(self::B, 27);
        $r = $this->mk(19, Zone::Row, 4);
        $this->begin([self::A, self::B], [self::A => 9, self::B => 9]);
        self::assertSame([$r->id => 3], $this->rowCosts(self::A));
        $this->nextTurn();
        self::assertSame([$r->id => 5], $this->rowCosts(self::B));
    }

    public function testFluffyBedNeedsToBeTheLastPreparedCardEvenBeforeASidewaysCardIsNotLast(): void
    {
        $this->prep(self::A, 37, 1);
        $r = $this->mk(19, Zone::Row, 4);
        $this->begin([self::A], [self::A => 9]);
        self::assertSame([$r->id => 4], $this->rowCosts(self::A), '037 が最後でなければ補正なし');
        $this->mk(37, Zone::Prep, self::B, 0);
        $this->globals->setExploreOrder([self::B]);
        $this->globals->setExploreIndex(0);
        $this->players->setExploreOutput(self::B, 9);
        $this->phase->beginTurn();
        self::assertSame([$r->id => 3], $this->rowCosts(self::B), '037 だけなら最後');
        $this->cards->setSideways($this->mk(1, Zone::Prep, self::B, 5), true);
        self::assertSame([$r->id => 4], $this->rowCosts(self::B), '後ろの横向きのカードも準備したカードなので 037 は最後ではない');
    }

    public function testTcX3LocalMapCancelsSpaceFood(): void
    {
        $this->prep(self::B, 27, 13);
        $r = $this->mk(19, Zone::Row, 4);
        $this->begin([self::C, self::B], [self::B => 9, self::C => 9]);
        $this->nextTurn();
        self::assertSame([$r->id => 5], $this->rowCosts(self::B), '最後の手番の 027 の +1 だけ');
        $this->phase->useOnExplore(self::B, $this->cards->findByCardNo(13)[0]->id, null);
        self::assertSame([$r->id => 4], $this->rowCosts(self::B), '+1 と -1 で印字のコスト');
    }

    public function testTcX4LocalMapDoesNotCarryOverToTheNextTurn(): void
    {
        $this->prep(self::B, 13);
        $this->prep(self::C, 1);
        $r = $this->mk(19, Zone::Row, 4);
        $this->begin([self::B, self::C], [self::B => 9, self::C => 9]);
        $this->phase->useOnExplore(self::B, $this->cards->findByCardNo(13)[0]->id, null);
        self::assertSame([$r->id => 3], $this->rowCosts(self::B));

        $this->nextTurn();
        self::assertSame([$r->id => 4], $this->rowCosts(self::C), 'C の手番に B の -1 は残らない');
        self::assertSame(0, $this->globals->exploreState()['cost_mod'] ?? null);
    }

    public function testCloudedTelescopeAndOversizedEngineBans(): void
    {
        $this->prep(self::A, 25);
        $r5 = $this->mk(5, Zone::Row, 2);
        $this->mk(56, Zone::Unknown, 0);
        $this->mk(5, Zone::ExploreDeck, 2);
        $this->begin([self::A], [self::A => 10]);
        $targets = $this->phase->targets(self::A);
        self::assertSame([], $targets['rowTargets'], '025: 列は探査できない');
        self::assertNotNull($targets['unknownTarget'], '056 は探査できる');
        self::assertSame([2], array_column($targets['deckTargets'], 'cost'), '山札の上は制限の対象外');

        $this->cards->removeFromGame([$this->cards->findByCardNo(25)[0]]);
        $this->prep(self::A, 26);
        $r36 = $this->mk(36, Zone::Row, 6);
        $r51 = $this->mk(51, Zone::Row, 8);
        self::assertSame([$r36->id => 6], $this->rowCosts(self::A), '026: 005 と 051 は探査できず、036 は探査できる');
        self::assertContains($r5->id, array_map(static fn(Card $c): int => $c->id, $this->cards->list(Zone::Row, 2)));
        self::assertNotContains($r51->id, array_keys($this->rowCosts(self::A)));
    }

    public function testHeldCardIsOnlyAnExploreTargetForTheOwner(): void
    {
        $r = $this->mk(19, Zone::Row, 4);
        $this->mk(5, Zone::ExploreDeck, 4);
        $this->globals->setLockedCard(['player_id' => self::B, 'card_id' => $r->id]);
        $this->begin([self::A, self::B], [self::A => 4, self::B => 4]);
        self::assertSame([], $this->phase->targets(self::A)['rowTargets']);
        self::assertSame([4], array_column($this->phase->targets(self::A)['deckTargets'], 'cost'));
        $this->nextTurn();
        self::assertSame([$r->id => 4], $this->rowCosts(self::B));
    }

    // ---- 011 ----

    public function testTc0111RadarAddsThePileTopToTheRowAndItCanBeExploredImmediately(): void
    {
        $this->prep(self::A, 11);
        $top = $this->mk(22, Zone::ExploreDeck, 4, 1);
        $this->mk(5, Zone::ExploreDeck, 4, 0);
        $this->mk(19, Zone::Row, 4, 0);
        self::assertSame('onExplore', $this->begin([self::A], [self::A => 4]));

        $usable = $this->phase->usableOnExplore(self::A);
        self::assertSame([['cardId' => $this->cards->findByCardNo(11)[0]->id, 'cardNo' => 11, 'kind' => 'radar', 'deckCosts' => [4]]], $usable);

        $result = $this->phase->useOnExplore(self::A, $usable[0]['cardId'], 4);
        self::assertSame('done', $result->transition, '011 は 1 手番に 1 回');
        $row = $this->cards->get($top->id);
        self::assertSame(Zone::Row, $row->zone);
        self::assertSame(1, $row->order, '空きスロットに加わる');
        $added = self::named($result->notifications, 'rowCardAdded')[0];
        self::assertSame($top->id, $added->args['card']['id']);
        self::assertContains($top->id, array_keys($this->rowCosts(self::A)), '直ちに探査できる');
    }

    public function testTc0112TheLastEightCostCardTriggersTheEndFlagButTheRoundContinues(): void
    {
        $this->prep(self::A, 11);
        $this->mk(48, Zone::ExploreDeck, 8);
        $this->begin([self::A], [self::A => 4]);
        $result = $this->phase->useOnExplore(self::A, $this->cards->findByCardNo(11)[0]->id, 8);
        self::assertTrue($this->globals->gameEndTriggered());
        self::assertSame(GlobalValues::END_PILE8, $this->globals->endReason());
        self::assertCount(1, self::named($result->notifications, 'gameEndTriggered'));
    }

    public function testRadarCannotChooseAnEmptyPileAndIsNotUsableWhenAllPilesAreEmpty(): void
    {
        $this->prep(self::A, 11);
        $this->mk(5, Zone::ExploreDeck, 2);
        self::assertSame('onExplore', $this->begin([self::A], [self::A => 4]));
        $this->expectException(ExploreRuleViolation::class);
        $this->phase->useOnExplore(self::A, $this->cards->findByCardNo(11)[0]->id, 4);
    }

    public function testTc0114ASidewaysRadarIsNotUsable(): void
    {
        $radar = $this->mk(11, Zone::Prep, self::A, 0);
        $this->cards->setSideways($radar, true);
        $this->mk(5, Zone::ExploreDeck, 2);
        self::assertSame('choose', $this->begin([self::A], [self::A => 4]));
    }

    public function testTurnStartGoesStraightToExploreWhenNothingIsUsable(): void
    {
        $this->prep(self::A, 1, 2);
        self::assertSame('choose', $this->begin([self::A], [self::A => 3]));
    }

    // ---- 013 ----

    public function testTc0131And0132LocalMapLowersTheCostAndKeepsTheOutput(): void
    {
        $this->prep(self::A, 13);
        $r = $this->mk(19, Zone::Row, 4);
        self::assertSame('onExplore', $this->begin([self::A], [self::A => 3]));
        self::assertSame([], $this->rowCosts(self::A), '使わなければ探査できない');

        $result = $this->phase->useOnExplore(self::A, $this->cards->findByCardNo(13)[0]->id, null);
        self::assertSame(Zone::Removed, $this->cards->findByCardNo(13)[0]->zone);
        self::assertSame([$r->id => 3], $this->rowCosts(self::A));
        self::assertSame(3, $this->players->exploreOutput(self::A), '探査出力は再計算しない');
        $removed = self::named($result->notifications, 'cardsRemoved')[0];
        self::assertSame('prep', $removed->args['from']);
        self::assertNull($removed->args['attached_card']);
    }

    public function testTc0135RemovingLocalMapAttachedToThePartRemovesThePartToo(): void
    {
        $host = $this->mk(46, Zone::Prep, self::A, 0);
        $map = $this->mk(13, Zone::Attached, $host->id, 1);
        $this->begin([self::A], [self::A => 3]);
        $result = $this->phase->useOnExplore(self::A, $map->id, null);

        self::assertSame(Zone::Removed, $this->cards->get($host->id)->zone);
        self::assertSame(Zone::Removed, $this->cards->get($map->id)->zone);
        $removed = self::named($result->notifications, 'cardsRemoved')[0];
        self::assertSame($host->id, $removed->args['card']['id']);
        self::assertSame($map->id, $removed->args['attached_card']['id']);
    }

    public function testTc0113BothOnExploreEffectsCanBeUsedInEitherOrder(): void
    {
        $this->prep(self::A, 11, 13);
        $this->mk(22, Zone::ExploreDeck, 4);
        $a = $this->begin([self::A], [self::A => 4]);
        self::assertSame('onExplore', $a);
        $first = $this->phase->useOnExplore(self::A, $this->cards->findByCardNo(13)[0]->id, null);
        self::assertSame('again', $first->transition);
        $second = $this->phase->useOnExplore(self::A, $this->cards->findByCardNo(11)[0]->id, 4);
        self::assertSame('done', $second->transition);
        $row = $this->cards->list(Zone::Row, 4);
        self::assertSame([22], array_map(static fn(Card $c): int => $c->cardNo, $row));
        self::assertSame([$row[0]->id => 3], $this->rowCosts(self::A));
    }

    // ---- 012 ----

    /**
     * 1 枚目を探査して獲得時効果の解決を終え、追加探査の判定結果を返す。
     */
    private function exploreFirst(int $player, Card $card, int $cost): string
    {
        $this->phase->explore($player, ExplorePhase::SOURCE_ROW, $card->id, $cost);
        $acquire = $this->phase->acquirePhase();
        self::assertSame('finished', $acquire->next()->transition);
        return $this->phase->afterAcquisitions($player);
    }

    public function testTc0121AdditionalExploreUsesTheSumOfCosts(): void
    {
        $this->prep(self::A, 12);
        $first = $this->mk(5, Zone::Row, 2, 0);
        $c4 = $this->mk(22, Zone::Row, 4, 0);
        $this->mk(36, Zone::Row, 6, 0);
        $this->begin([self::A], [self::A => 6]);

        self::assertSame('additional', $this->exploreFirst(self::A, $first, 2));
        self::assertSame([$c4->id], array_column($this->phase->targets(self::A)['rowTargets'], 'cardId'));
        $state = $this->globals->exploreState();
        self::assertSame(2, $state['sum_cost'] ?? null);

        $this->phase->explore(self::A, ExplorePhase::SOURCE_ROW, $c4->id, 4);
        self::assertSame(6, $this->globals->exploreState()['sum_cost'] ?? null);
        self::assertSame('done', $this->phase->afterAcquisitions(self::A), '追加探査は 1 回だけ');
        $this->expectException(ExploreRuleViolation::class);
        $this->phase->explore(self::A, ExplorePhase::SOURCE_ROW, $this->mk(36, Zone::Row, 6, 1)->id, 6);
    }

    public function testTc0122WithoutAnyExplorationThereIsNoAdditionalExplore(): void
    {
        $this->prep(self::A, 12);
        $this->mk(5, Zone::Row, 2);
        $this->begin([self::A], [self::A => 6]);
        self::assertSame('done', $this->phase->afterAcquisitions(self::A));
    }

    public function testTc0123RecordedCostsUseTheEffectiveCostWithModifiers(): void
    {
        $this->prep(self::A, 6, 12);
        $first = $this->mk(19, Zone::Row, 4);
        $c2 = $this->mk(5, Zone::Row, 2);
        $c4 = $this->mk(22, Zone::Row, 4, 1);
        $this->begin([self::A], [self::A => 5]);

        self::assertSame('additional', $this->exploreFirst(self::A, $first, 3));
        self::assertSame(3, $this->globals->exploreState()['sum_cost'] ?? null);
        $ids = array_column($this->phase->targets(self::A)['rowTargets'], 'cardId');
        self::assertContains($c2->id, $ids, '実効コスト 2 で合計 5');
        self::assertNotContains($c4->id, $ids, '[天の川銀河] 実効 3 で合計 6');
    }

    public function testTc0124AdditionalAcquireResolvesAcquireEffects(): void
    {
        $this->prep(self::A, 12, 1);
        $first = $this->mk(5, Zone::Row, 2, 0);
        $second = $this->mk(4, Zone::Row, 2, 1);
        $this->begin([self::A], [self::A => 4]);
        self::assertSame('additional', $this->exploreFirst(self::A, $first, 2));
        $this->phase->explore(self::A, ExplorePhase::SOURCE_ROW, $second->id, 2);
        $next = $this->phase->acquirePhase()->next();
        self::assertSame('exclude', $next->transition, '004 の獲得時効果を通常どおり解決する');
        self::assertSame(self::A, $next->playerId);
    }

    public function testAdditionalExploreIsDroppedWhenNothingCanBeExploredOrTheSourceIsGone(): void
    {
        $this->prep(self::A, 12);
        $first = $this->mk(5, Zone::Row, 2);
        $this->mk(36, Zone::Row, 6);
        $this->begin([self::A], [self::A => 2]);
        self::assertSame('done', $this->exploreFirst(self::A, $first, 2), '追加で探査できる対象が無い');

        $this->nextTurnReset();
        $this->prep(self::B, 6, 12);
        $this->mk(19, Zone::Row, 4);
        $third = $this->mk(22, Zone::Row, 4, 1);
        $this->globals->setExploreOrder([self::B]);
        $this->globals->setExploreIndex(0);
        $this->players->setExploreOutput(self::B, 6);
        $this->phase->beginTurn();
        $this->phase->explore(self::B, ExplorePhase::SOURCE_ROW, $third->id, 3);
        $this->phase->acquirePhase()->next();
        $this->cards->removeFromGame([$this->cards->list(Zone::Prep, self::B)[1]]);
        self::assertSame('done', $this->phase->afterAcquisitions(self::B), '012 が準備場に無ければ使えない');
    }

    private function nextTurnReset(): void
    {
        $this->globals->setExploreState(null);
    }

    public function testExploreAlreadyExploredStillThrowsWithoutTheAdditionalEffect(): void
    {
        $this->prep(self::A, 1);
        $a = $this->mk(5, Zone::Row, 2, 0);
        $b = $this->mk(6, Zone::Row, 2, 1);
        $this->begin([self::A], [self::A => 9]);
        $this->phase->explore(self::A, ExplorePhase::SOURCE_ROW, $a->id, 2);
        $this->expectException(ExploreRuleViolation::class);
        $this->phase->explore(self::A, ExplorePhase::SOURCE_ROW, $b->id, 2);
    }
}
