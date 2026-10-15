<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\ResolveEntryRepository;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\AcquirePhase;
use Bga\Games\MinimumJourney\Flow\DrawResolver;
use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Flow\ExplorePhase;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Flow\ReturnPhase;
use MinimumJourneyTests\Support\DatabaseTestCase;

/**
 * 獲得時の効果（004・034・040・055・058。008 §5）と、帰還フェイズ①②との接続を、実 DB で動かす。
 */
final class AcquireEffectsTest extends DatabaseTestCase
{
    private const A = 2330001;
    private const B = 2330002;
    private const C = 2330003;

    private ExplorePhase $explore;
    private AcquirePhase $acquire;
    private ReturnPhase $return;

    protected function setUp(): void
    {
        parent::setUp();
        $ids = [self::A, self::B, self::C];
        $this->addPlayers($ids);
        $this->globals->setRoundNo(2);
        $this->globals->setPhase(GlobalValues::PHASE_EXPLORE);
        $name = static fn(int $id): string => 'P' . $id;
        $this->explore = new ExplorePhase(
            $this->cards,
            $this->players,
            $this->globals,
            new ExploreOutputCalculator($this->cards, $this->globals, fn(): array => $ids),
            $name,
        );
        $this->acquire = $this->explore->acquirePhase();
        $this->return = new ReturnPhase($this->cards, $this->players, $this->globals, $name);
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

    private function begin(int $player, int $output): void
    {
        $this->globals->setExploreOrder([$player]);
        $this->globals->setExploreIndex(0);
        $this->players->setExploreOutput($player, $output);
        $this->explore->beginTurn();
    }

    private function card(int $no): Card
    {
        return $this->cards->findByCardNo($no)[0];
    }

    /**
     * @param list<Notification> $notes
     * @return list<Notification>
     */
    private static function named(array $notes, string $name): array
    {
        return array_values(array_filter($notes, static fn(Notification $n): bool => $n->name === $name));
    }

    /**
     * @return list<int>
     */
    private function prepNos(int $player): array
    {
        return array_map(static fn(Card $c): int => $c->cardNo, $this->cards->list(Zone::Prep, $player));
    }

    // ---- 004 ----

    public function testTc0041ExcludesTheChosenCardAndKeepsTheOutput(): void
    {
        $this->prep(self::A, 2, 3, 44);
        $row = $this->mk(4, Zone::Row, 2);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $row->id, 0);

        $step = $this->acquire->next();
        self::assertSame('exclude', $step->transition);
        self::assertSame(self::A, $step->playerId);
        self::assertCount(3, $this->acquire->prepCandidates(self::A));

        $result = $this->acquire->excludePrepRequired(self::A, $this->card(3)->id);
        self::assertSame('done', $result->transition);
        self::assertSame([2, 44], $this->prepNos(self::A));
        self::assertSame(Zone::Removed, $this->card(3)->zone);
        self::assertSame(6, $this->players->exploreOutput(self::A));
        self::assertSame('finished', $this->acquire->next()->transition);
        $removed = self::named($result->notifications, 'cardsRemoved')[0];
        self::assertSame('prep', $removed->args['from']);
        self::assertSame(Zone::Acquired, $this->card(4)->zone);
    }

    public function testTc0042SidewaysCardIsACandidate(): void
    {
        $this->mk(29, Zone::Prep, self::A, 0);
        $this->cards->setSideways($this->card(29), true);
        $row = $this->mk(4, Zone::Row, 2);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $row->id, 0);
        self::assertSame('exclude', $this->acquire->next()->transition);
        self::assertSame([29], array_map(static fn(Card $c): int => $c->cardNo, $this->acquire->prepCandidates(self::A)));
        $this->acquire->excludePrepRequired(self::A, $this->card(29)->id);
        self::assertSame(Zone::Removed, $this->card(29)->zone);
    }

    public function testTc0043EmptyPrepAreaSkipsTheEffect(): void
    {
        $row = $this->mk(4, Zone::Row, 2);
        $this->mk(3, Zone::Facedown, self::A, 0);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $row->id, 0);
        self::assertSame('finished', $this->acquire->next()->transition, '裏向きの別置き場のカードは候補にならない');
    }

    public function testTc0045PartIsOneCandidateAndTakesItsAttachedCardAlong(): void
    {
        $host = $this->mk(46, Zone::Prep, self::A, 0);
        $attached = $this->mk(18, Zone::Attached, $host->id, 1);
        $row = $this->mk(4, Zone::Row, 2);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $row->id, 0);
        $this->acquire->next();
        self::assertSame([$host->id], array_map(static fn(Card $c): int => $c->id, $this->acquire->prepCandidates(self::A)));
        $result = $this->acquire->excludePrepRequired(self::A, $host->id);
        self::assertSame(Zone::Removed, $this->cards->get($host->id)->zone);
        self::assertSame(Zone::Removed, $this->cards->get($attached->id)->zone);
        self::assertSame($attached->id, self::named($result->notifications, 'cardsRemoved')[0]->args['attached_card']['id']);
    }

    public function testExcludeRequiresAValidCandidateAndTheRightPlayer(): void
    {
        $this->prep(self::A, 2);
        $this->prep(self::B, 3);
        $row = $this->mk(4, Zone::Row, 2);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $row->id, 0);
        $this->acquire->next();
        try {
            $this->acquire->excludePrepRequired(self::A, $this->card(3)->id);
            self::fail('他人のカードは選べない');
        } catch (ExploreRuleViolation $e) {
            self::assertSame(ExploreRuleViolation::NOT_A_TARGET, $e->reason);
        }
        try {
            $this->acquire->excludePrepRequired(self::B, $this->card(3)->id);
            self::fail('手番でないプレイヤーは選べない');
        } catch (ExploreRuleViolation $e) {
            self::assertSame(ExploreRuleViolation::NOT_YOUR_TURN, $e->reason);
        }
    }

    public function testZombieExcludesTheCardWithTheLowestFame(): void
    {
        $this->prep(self::A, 1, 2, 3);
        $row = $this->mk(4, Zone::Row, 2);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $row->id, 0);
        $this->acquire->next();
        $this->acquire->zombieExcludeRequired(self::A);
        self::assertSame([1, 2], $this->prepNos(self::A), '003（名声 1）');
    }

    // ---- 034 ----

    public function testTc0341CandidatesAreSolarOrMilkyWayOnlyAndTheTwoLowPiles(): void
    {
        $r4 = $this->mk(4, Zone::Row, 2);
        $r19 = $this->mk(19, Zone::Row, 4);
        $this->mk(51, Zone::Row, 8);
        $this->mk(36, Zone::Row, 6);
        $this->mk(5, Zone::ExploreDeck, 2);
        $this->mk(22, Zone::ExploreDeck, 4);
        $this->mk(40, Zone::ExploreDeck, 6);
        $this->mk(48, Zone::ExploreDeck, 8);
        $this->mk(56, Zone::Unknown, 0);
        $c = $this->acquire->freeCandidates(self::A);
        self::assertEqualsCanonicalizing([$r4->id, $r19->id], array_map(static fn(Card $x): int => $x->id, $c['rows']));
        self::assertSame([2, 4], $c['decks']);
    }

    public function testTc0342And0041ChainedColonyCampTriggersAndDustVacuumStays(): void
    {
        $this->prep(self::A, 1);
        $dust = $this->mk(34, Zone::Row, 6);
        $r4 = $this->mk(4, Zone::Row, 2);
        $this->begin(self::A, 8);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $dust->id, 0);

        self::assertSame('freeAcquire', $this->acquire->next()->transition);
        $result = $this->acquire->freeAcquire(self::A, 'row', $r4->id);
        $acquired = self::named($result->notifications, 'cardAcquired')[0];
        self::assertTrue($acquired->args['free']);
        self::assertSame(Zone::Acquired, $this->cards->get($r4->id)->zone);
        self::assertSame($dust->id, $this->cards->get($dust->id)->id);
        self::assertSame(Zone::Acquired, $this->cards->get($dust->id)->zone, '034 も獲得エリアに残る');
        self::assertSame(0, $this->cards->count(Zone::Row, 2), '列は補充しない');

        self::assertSame('exclude', $this->acquire->next()->transition, '004 が連鎖して発動する');
        $this->acquire->excludePrepRequired(self::A, $this->card(1)->id);
        self::assertSame('finished', $this->acquire->next()->transition);
        self::assertSame(6, $this->globals->exploreState()['sum_cost'] ?? null, '無料の獲得は探査コストの合計に含めない');
    }

    public function testFreeAcquireFromAPileTopRevealsItAndNoCostIsPaid(): void
    {
        $dust = $this->mk(34, Zone::Row, 6);
        $this->mk(5, Zone::ExploreDeck, 2, 0);
        $top = $this->mk(7, Zone::ExploreDeck, 2, 1);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $dust->id, 0);
        self::assertSame(6, $this->globals->exploreState()['sum_cost'] ?? null);
        $this->acquire->next();
        $result = $this->acquire->freeAcquire(self::A, 'deck', 2);
        self::assertSame(Zone::Acquired, $this->cards->get($top->id)->zone);
        $note = self::named($result->notifications, 'cardAcquired')[0];
        self::assertSame($top->id, $note->args['card']['id']);
        self::assertSame(6, $this->globals->exploreState()['sum_cost'] ?? null, '034 の無料獲得は合計に含めない');
        $this->expectException(ExploreRuleViolation::class);
        $this->acquire->freeAcquire(self::A, 'deck', 6);
    }

    public function testTc0343HeldCardIsNotACandidateForTheOthers(): void
    {
        $r19 = $this->mk(19, Zone::Row, 4);
        $this->globals->setLockedCard(['player_id' => self::B, 'card_id' => $r19->id]);
        self::assertSame([], $this->acquire->freeCandidates(self::A)['rows']);
        self::assertSame([$r19->id], array_map(static fn(Card $x): int => $x->id, $this->acquire->freeCandidates(self::B)['rows']));
    }

    public function testTc0344NoCandidatesMeansTheEffectDoesNotTrigger(): void
    {
        $dust = $this->mk(34, Zone::Row, 6);
        $this->mk(36, Zone::Row, 6, 1);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $dust->id, 0);
        self::assertSame('finished', $this->acquire->next()->transition);
    }

    public function testDustVacuumIgnoresTheClouded25And26Bans(): void
    {
        $this->prep(self::A, 25, 26);
        $r4 = $this->mk(4, Zone::Row, 2);
        self::assertSame([$r4->id], array_map(static fn(Card $x): int => $x->id, $this->acquire->freeCandidates(self::A)['rows']), '008 N-12');
    }

    public function testZombieDustVacuumPicksTheHighestFameRowCard(): void
    {
        $dust = $this->mk(34, Zone::Row, 6);
        $this->mk(5, Zone::Row, 2, 0);
        $best = $this->mk(8, Zone::Row, 2, 1);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $dust->id, 0);
        $this->acquire->next();
        $this->acquire->zombieFreeAcquire(self::A);
        self::assertSame(Zone::Acquired, $this->cards->get($best->id)->zone);
    }

    // ---- 040 ----

    public function testTc0401AndTcA2BeaconGoesOnTopAfterTheShuffleUnlessExcluded(): void
    {
        $this->prep(self::A, 1);
        $this->mk(2, Zone::Deck, self::A, 0);
        $this->mk(3, Zone::Deck, self::A, 1);
        $beacon = $this->mk(40, Zone::Row, 6);
        $this->begin(self::A, 6);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $beacon->id, 0);
        self::assertSame('finished', $this->acquire->next()->transition);
        self::assertSame([['player_id' => self::A, 'card_no' => 40]], $this->globals->topAfterShuffle());

        $this->globals->setPhase(GlobalValues::PHASE_RETURN);
        $notes = $this->return->shuffle([self::A, self::B, self::C]);
        $top = $this->cards->peekTop(Zone::Deck, self::A, 1)[0];
        self::assertSame(40, $top->cardNo);
        self::assertSame([], $this->globals->topAfterShuffle(), '記録は消す');
        self::assertCount(1, self::named($notes, 'beaconPlaced'));
        self::assertArrayNotHasKey('card', self::named($notes, 'beaconPlaced')[0]->args, '新しい ID は載せない');
    }

    public function testTcA2ExcludedBeaconIsNotPlaced(): void
    {
        $this->prep(self::A, 1);
        $this->mk(2, Zone::Deck, self::A, 0);
        $beacon = $this->mk(40, Zone::Row, 6);
        $this->begin(self::A, 20);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $beacon->id, 0);
        $this->acquire->next();
        // 同じ手番に 055 を獲得し（追加探査の 012 を想定）、040 を除外する
        $hole = $this->mk(55, Zone::Acquired, self::A, 5);
        $this->acquire->enqueue($hole->id, self::A);
        self::assertSame('blackHole', $this->acquire->next()->transition);
        $this->acquire->excludeDeck(self::A, [$beacon->id]);

        $this->globals->setPhase(GlobalValues::PHASE_RETURN);
        $notes = $this->return->shuffle([self::A, self::B, self::C]);
        self::assertSame([], self::named($notes, 'beaconPlaced'));
        self::assertSame(Zone::Removed, $this->card(40)->zone);
        self::assertNotSame(40, $this->cards->peekTop(Zone::Deck, self::A, 1)[0]->cardNo);
    }

    public function testTcA3BeaconSwappedIntoTheDeckInThePrepPhaseIsNotRecorded(): void
    {
        $this->globals->setPhase(GlobalValues::PHASE_PREP);
        $beacon = $this->mk(40, Zone::Deck, self::A, 0);
        $this->acquire->enqueue($beacon->id, self::A);
        self::assertSame('finished', $this->acquire->next()->transition);
        self::assertSame([], $this->globals->topAfterShuffle());
    }

    // ---- 055 ----

    /**
     * 055 を探査で獲得し、状態 47 の直前（blackHole）まで進める。
     */
    private function exploreBlackHole(): Card
    {
        $hole = $this->mk(55, Zone::Row, 8);
        $this->begin(self::A, 8);
        $this->explore->explore(self::A, ExplorePhase::SOURCE_ROW, $hole->id, 0);
        self::assertSame('blackHole', $this->acquire->next()->transition);
        return $hole;
    }

    private function deckOfA(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $i => $no) {
            $this->mk($no, Zone::Deck, self::A, $i);
        }
        $this->prep(self::A, 2, 3);
        $this->mk(9, Zone::Facedown, self::A, 0);
        $this->mk(57, Zone::Charm, self::A);
    }

    public function testTc0551CandidatesCoverTheWholeDeckExceptTheCharm(): void
    {
        $this->deckOfA();
        $hole = $this->exploreBlackHole();
        $candidates = $this->acquire->deckCandidates(self::A);
        $nos = array_map(static fn(Card $c): int => $c->cardNo, $candidates);
        self::assertCount(8 + 2 + 1 + 1, $candidates, '山札 8、準備場 2、獲得 1、裏向き 1');
        self::assertNotContains(57, $nos, 'お守りは候補外');
        self::assertSame($nos, array_values(array_map(static fn(Card $c): int => $c->cardNo, $candidates)));
        $sorted = $nos;
        sort($sorted);
        self::assertSame($sorted, $nos, '山の順序を漏らさない並び');

        $facedown = $this->card(9);
        $before = array_map(static fn(Card $c): int => $c->id, $this->cards->list(Zone::Deck, self::A));
        $result = $this->acquire->excludeDeck(self::A, [$facedown->id, $this->card(5)->id]);
        self::assertSame(Zone::Removed, $this->cards->get($facedown->id)->zone);
        self::assertSame(Zone::Removed, $this->card(5)->zone);
        self::assertSame(2, count(self::named($result->notifications, 'cardsRemoved')));
        self::assertSame('finished', $this->acquire->next()->transition);
        // 山札と別置き場の ID は付け替わる（本人のクライアントが覚えている ID を使えなくする）
        $after = array_map(static fn(Card $c): int => $c->id, $this->cards->list(Zone::Deck, self::A));
        self::assertSame([], array_values(array_intersect($before, $after)));
        self::assertSame(Zone::Acquired, $this->cards->get($hole->id)->zone, '獲得エリアの 055 は付け替えない');
    }

    public function testTc0552ZeroCardsIsAllowed(): void
    {
        $this->deckOfA();
        $this->exploreBlackHole();
        $result = $this->acquire->excludeDeck(self::A, []);
        self::assertSame('done', $result->transition);
        self::assertSame([], self::named($result->notifications, 'cardsRemoved'));
        self::assertSame(8, $this->cards->count(Zone::Deck, self::A));
    }

    public function testTc0553CanExcludeItselfAndTcSelectionRules(): void
    {
        $this->deckOfA();
        $hole = $this->exploreBlackHole();
        try {
            $this->acquire->excludeDeck(self::A, [$this->card(1)->id, $this->card(2)->id, $this->card(3)->id]);
            self::fail('最大 2 枚');
        } catch (ExploreRuleViolation) {
            self::assertSame(8, $this->cards->count(Zone::Deck, self::A));
        }
        try {
            $this->acquire->excludeDeck(self::A, [$this->card(57)->id]);
            self::fail('お守りは選べない');
        } catch (ExploreRuleViolation) {
            self::assertSame(Zone::Charm, $this->card(57)->zone);
        }
        $this->acquire->excludeDeck(self::A, [$hole->id]);
        self::assertSame(Zone::Removed, $this->cards->get($hole->id)->zone);
    }

    public function testTc0554PartIsASingleCandidateAndTakesItsAttachedCardAlong(): void
    {
        $host = $this->mk(46, Zone::Prep, self::A, 0);
        $attached = $this->mk(18, Zone::Attached, $host->id, 1);
        $this->exploreBlackHole();
        $ids = array_map(static fn(Card $c): int => $c->id, $this->acquire->deckCandidates(self::A));
        self::assertContains($host->id, $ids);
        self::assertNotContains($attached->id, $ids);
        $this->acquire->excludeDeck(self::A, [$host->id]);
        self::assertSame(Zone::Removed, $this->cards->get($attached->id)->zone);
    }

    public function testTcA5DeckFacesAreOnlySentToTheOwnerWhileResolving(): void
    {
        $this->deckOfA();
        $this->mk(1, Zone::Deck, self::B, 0);
        self::assertSame([], $this->acquire->deckViewFor(self::A), '解決前は見せない');
        $this->exploreBlackHole();
        $view = $this->acquire->deckViewFor(self::A);
        self::assertCount(12, $view);
        self::assertContains('facedown', array_column($view, 'zone'), '別置き場の裏向きのカードの券面も本人には見せる');
        self::assertSame([], $this->acquire->deckViewFor(self::B), '他人には送らない');
        self::assertSame([], $this->acquire->deckViewFor(self::C));

        $this->acquire->excludeDeck(self::A, []);
        self::assertSame([], $this->acquire->deckViewFor(self::A), '解決後は見せない');
    }

    public function testZombieBlackHoleExcludesNothing(): void
    {
        $this->deckOfA();
        $this->exploreBlackHole();
        $before = $this->countAll();
        $this->acquire->excludeDeck(self::A, []);
        self::assertSame($before, $this->countAll());
        self::assertSame(0, $this->cards->count(Zone::Removed));
    }

    // ---- 058 ----

    private function charmAt(int $no, int $holder): void
    {
        $this->mk($no, Zone::Charm, $holder);
    }

    /**
     * @param array<int, int> $outputs
     */
    private function setOutputs(array $outputs): void
    {
        $this->globals->setExploreOrder([self::A, self::B, self::C]);
        foreach ($outputs as $id => $o) {
            $this->players->setExploreOutput($id, $o);
        }
    }

    public function testTc0581TheNewHolderMayExcludeOneCardFromTheirPrepArea(): void
    {
        $this->charmAt(58, self::B);
        $this->setOutputs([self::A => 5, self::B => 7, self::C => 6]);
        $this->prep(self::A, 2, 3);
        $step = $this->return->moveCharmStep([self::A, self::B, self::C]);
        self::assertSame('exclude', $step->transition);
        self::assertSame(self::A, $step->playerId);
        self::assertSame(self::A, $this->cards->list(Zone::Charm)[0]->locationArg);

        $result = $this->acquire->excludePrepOptional(self::A, $this->card(3)->id);
        self::assertSame('done', $result->transition);
        self::assertSame([2], $this->prepNos(self::A));
        self::assertCount(1, self::named($result->notifications, 'cardsRemoved'));
    }

    public function testExcludingNothingIsAllowedAndOnlyTheHolderMayAct(): void
    {
        $this->charmAt(58, self::B);
        $this->setOutputs([self::A => 5, self::B => 7, self::C => 6]);
        $this->prep(self::A, 2);
        $this->return->moveCharmStep([self::A, self::B, self::C]);
        try {
            $this->acquire->excludePrepOptional(self::B, null);
            self::fail('新所有者以外は選べない');
        } catch (ExploreRuleViolation) {
            self::assertSame(1, $this->cards->count(Zone::Prep, self::A));
        }
        self::assertSame([], $this->acquire->excludePrepOptional(self::A, null)->notifications);
        self::assertSame([2], $this->prepNos(self::A));
    }

    public function testTc0582TheEffectTriggersEvenWhenTheHolderDoesNotChange(): void
    {
        $this->charmAt(58, self::B);
        $this->setOutputs([self::A => 7, self::B => 5, self::C => 6]);
        $this->prep(self::B, 3);
        $step = $this->return->moveCharmStep([self::A, self::B, self::C]);
        self::assertSame('exclude', $step->transition);
        self::assertSame(self::B, $step->playerId);
        self::assertSame([], self::named($step->notifications, 'amuletMoved'));
    }

    public function testTc0583EmptyPrepAreaSkipsTheState(): void
    {
        $this->charmAt(58, self::B);
        $this->setOutputs([self::A => 5, self::B => 7, self::C => 6]);
        $this->mk(3, Zone::Facedown, self::A, 0);
        self::assertSame('next', $this->return->moveCharmStep([self::A, self::B, self::C])->transition);
    }

    public function testTc0584PartIsOneCandidate(): void
    {
        $this->charmAt(58, self::A);
        $this->setOutputs([self::A => 1, self::B => 7, self::C => 6]);
        $host = $this->mk(46, Zone::Prep, self::A, 0);
        $attached = $this->mk(18, Zone::Attached, $host->id, 1);
        $this->return->moveCharmStep([self::A, self::B, self::C]);
        self::assertSame([$host->id], array_map(static fn(Card $c): int => $c->id, $this->acquire->prepCandidates(self::A)));
        $this->acquire->excludePrepOptional(self::A, $host->id);
        self::assertSame(Zone::Removed, $this->cards->get($attached->id)->zone);
    }

    public function testSafePassageCharmHasNoAcquireEffect(): void
    {
        $this->charmAt(57, self::B);
        $this->setOutputs([self::A => 5, self::B => 7, self::C => 6]);
        $this->prep(self::A, 2);
        self::assertSame('next', $this->return->moveCharmStep([self::A, self::B, self::C])->transition);
    }

    // ---- 036 ----

    public function testTc036SwappedInRowCardTriggersItsAcquireEffectAfterwards(): void
    {
        $this->globals->setPhase(GlobalValues::PHASE_PREP);
        $this->cards->create(57, 0, Zone::Charm, self::A);
        $resolver = new DrawResolver(
            $this->cards,
            $this->players,
            $this->globals,
            new ResolveEntryRepository($this->db),
            new ExploreOutputCalculator($this->cards, $this->globals),
            static fn(int $id): string => 'P' . $id,
            fn(): array => [self::A, self::B, self::C],
        );
        $this->mk(5, Zone::Deck, self::A, 5);
        $this->mk(1, Zone::Deck, self::A, 4);
        $this->mk(1, Zone::Deck, self::A, 3);
        $worm = $this->mk(36, Zone::Prep, self::A, 0);
        $row = $this->mk(4, Zone::Row, 2);

        $resolver->enqueue([self::A => $worm]);
        $step = $resolver->advance();
        self::assertSame('wormhole', $step->transition);
        $resolver->wormhole(self::A, $row->id);

        self::assertTrue($this->acquire->hasPending());
        $next = $this->acquire->next();
        self::assertSame('exclude', $next->transition, '004 が 044 で解決される');
        self::assertSame(self::A, $next->playerId);
        self::assertSame([36], $this->prepNos(self::A));
        $this->acquire->excludePrepRequired(self::A, $worm->id);
        self::assertSame('finished', $this->acquire->next()->transition);
        $inDeck = array_map(static fn(Card $c): int => $c->cardNo, $this->cards->list(Zone::Deck, self::A));
        self::assertContains(4, $inDeck, '004 は山札に加わったまま');
        self::assertSame([], $this->globals->topAfterShuffle());
    }
}
