<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Data\Zone;
use MinimumJourneyTests\Support\DatabaseTestCase;

/**
 * CardRepository・PlayerValues の SQL を実 DB で動かして確かめる。
 */
final class CardRepositoryTest extends DatabaseTestCase
{
    private const P1 = 101;
    private const P2 = 102;

    /**
     * 山札 $n 枚を作る（card_no は 4 から。order は 0 から）。
     *
     * @return list<Card>
     */
    private function makeDeck(int $playerId, int $n): array
    {
        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = $this->cards->create(4 + $i, 0, Zone::Deck, $playerId, $i);
        }
        return $cards;
    }

    public function testCreateAndGet(): void
    {
        $card = $this->cards->create(7, 2, Zone::Deck, self::P1, 3);

        $loaded = $this->cards->get($card->id);
        self::assertSame(7, $loaded->cardNo);
        self::assertSame(2, $loaded->art);
        self::assertSame(Zone::Deck, $loaded->zone);
        self::assertSame(self::P1, $loaded->locationArg);
        self::assertSame(3, $loaded->order);
        self::assertFalse($loaded->sideways);
        self::assertCount(1, $this->cards->findByCardNo(7));
    }

    public function testGetMissingCardThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cards->get(999);
    }

    public function testDrawTopToPrepTakesLargestOrderFirst(): void
    {
        $deck = $this->makeDeck(self::P1, 4);

        $first = $this->cards->drawTopToPrep(self::P1);
        self::assertNotNull($first);
        self::assertSame($deck[3]->id, $first->id);
        self::assertSame(Zone::Prep, $first->zone);
        self::assertSame(self::P1, $first->locationArg);
        self::assertSame(1, $first->order);

        $second = $this->cards->drawTopToPrep(self::P1);
        self::assertSame($deck[2]->id, $second?->id);
        self::assertSame(2, $second->order);
        self::assertSame(2, $this->cards->count(Zone::Deck, self::P1));
        self::assertSame(2, $this->cards->count(Zone::Prep, self::P1));
    }

    public function testDrawTopFromEmptyDeckReturnsNull(): void
    {
        self::assertNull($this->cards->drawTopToPrep(self::P1));
        self::assertNull($this->cards->drawTopToFacedown(self::P1));
    }

    public function testDrawTopToFacedown(): void
    {
        $deck = $this->makeDeck(self::P1, 3);

        $drawn = $this->cards->drawTopToFacedown(self::P1);

        self::assertSame($deck[2]->id, $drawn?->id);
        self::assertSame(Zone::Facedown, $drawn->zone);
        self::assertSame(2, $this->cards->count(Zone::Deck, self::P1));
    }

    public function testPeekTopDoesNotMoveAndOrdersTopFirst(): void
    {
        $deck = $this->makeDeck(self::P1, 5);

        $top = $this->cards->peekTop(Zone::Deck, self::P1, 2);

        self::assertSame([$deck[4]->id, $deck[3]->id], array_map(static fn(Card $c): int => $c->id, $top));
        self::assertSame(5, $this->cards->count(Zone::Deck, self::P1));
        self::assertSame([], $this->cards->peekTop(Zone::Deck, self::P1, 0));
    }

    public function testPutOnTopAndBottom(): void
    {
        $deck = $this->makeDeck(self::P1, 3);
        $prep = $this->cards->create(20, 0, Zone::Prep, self::P1, 1);
        $other = $this->cards->create(21, 0, Zone::Prep, self::P1, 2);

        $this->cards->putOnTop($prep, Zone::Deck, self::P1);
        $this->cards->putOnBottom($other, Zone::Deck, self::P1);

        self::assertSame($prep->id, $this->cards->peekTop(Zone::Deck, self::P1, 1)[0]->id);
        self::assertSame($other->id, $this->cards->list(Zone::Deck, self::P1)[0]->id);
        self::assertSame(-1, $this->cards->get($other->id)->order);
        self::assertSame(3, $this->cards->get($prep->id)->order);
        self::assertSame(5, $this->cards->count(Zone::Deck, self::P1));
        self::assertSame($deck[2]->id, $this->cards->peekTop(Zone::Deck, self::P1, 2)[1]->id);
    }

    public function testShuffleKeepsCountAndIds(): void
    {
        $deck = $this->makeDeck(self::P1, 12);
        $this->makeDeck(self::P2, 3);
        $before = $this->ids(Zone::Deck, self::P1);

        $this->cards->shuffleDeck(self::P1);

        $after = $this->ids(Zone::Deck, self::P1);
        self::assertCount(12, $after);
        $sortedAfter = $after;
        sort($sortedAfter);
        $sortedBefore = $before;
        sort($sortedBefore);
        self::assertSame($sortedBefore, $sortedAfter);
        self::assertSame(range(0, 11), array_map(
            static fn(Card $c): int => $c->order,
            $this->cards->list(Zone::Deck, self::P1),
        ));
        self::assertNotSame($before, $after, 'order should change with this seed');
        // 他のプレイヤーの山札は触らない
        self::assertSame(3, $this->cards->count(Zone::Deck, self::P2));
        self::assertCount(12, $deck);
    }

    public function testShuffleEmptyZoneIsNoop(): void
    {
        $this->cards->shuffleZone(Zone::Deck, self::P1);
        self::assertSame(0, $this->countAll());
    }

    public function testHideIdsReassignsAndReturnsMap(): void
    {
        $deck = $this->makeDeck(self::P1, 4);
        $oldIds = array_map(static fn(Card $c): int => $c->id, $deck);
        $untouched = $this->cards->create(30, 0, Zone::Row, 2, 0);

        $map = $this->cards->hideIds($deck);

        self::assertEqualsCanonicalizing($oldIds, array_keys($map));
        self::assertCount(4, array_unique($map));
        foreach ($map as $old => $new) {
            self::assertNotContains($new, $oldIds);
            self::assertGreaterThan($untouched->id, $new);
        }
        // 同じカードが新しい ID で残り、古い ID は消える
        $byNo = [];
        foreach ($deck as $card) {
            $moved = $this->cards->get($map[$card->id]);
            self::assertSame($card->cardNo, $moved->cardNo);
            self::assertSame($card->order, $moved->order);
            self::assertSame(self::P1, $moved->locationArg);
            $byNo[$card->cardNo] = true;
        }
        self::assertSame(5, $this->countAll());
        self::assertSame($untouched->id, $this->cards->get($untouched->id)->id);
        $this->expectException(\InvalidArgumentException::class);
        $this->cards->get($oldIds[0]);
    }

    public function testHideIdsUpdatesAttachedReference(): void
    {
        $host = $this->cards->create(CardRepository::ATTACH_HOST_CARD_NO, 0, Zone::Prep, self::P1, 1);
        $attached = $this->cards->create(12, 0, Zone::Attached, $host->id, 1);

        $map = $this->cards->hideIds([$host, $attached]);

        $newHost = $this->cards->get($map[$host->id]);
        $newAttached = $this->cards->get($map[$attached->id]);
        self::assertSame(Zone::Attached, $newAttached->zone);
        self::assertSame($newHost->id, $newAttached->locationArg);
        self::assertSame([$newAttached->id], array_map(
            static fn(Card $c): int => $c->id,
            $this->cards->attachedTo($newHost),
        ));
    }

    public function testMovingHost046MovesAttachedCard(): void
    {
        $host = $this->cards->create(CardRepository::ATTACH_HOST_CARD_NO, 0, Zone::Prep, self::P1, 1);
        $attached = $this->cards->create(12, 0, Zone::Prep, self::P1, 2);
        $this->cards->attach($host, $attached);
        self::assertSame(Zone::Attached, $this->cards->get($attached->id)->zone);

        $this->cards->move($host, Zone::Deck, self::P1);

        $movedAttached = $this->cards->get($attached->id);
        self::assertSame(Zone::Deck, $movedAttached->zone);
        self::assertSame(self::P1, $movedAttached->locationArg);
        self::assertSame(2, $this->cards->count(Zone::Deck, self::P1));
        self::assertSame(0, $this->cards->count(Zone::Prep, self::P1));
    }

    public function testRemoveFromGameMovesAttachedToo(): void
    {
        $host = $this->cards->create(CardRepository::ATTACH_HOST_CARD_NO, 0, Zone::Prep, self::P1, 1);
        $attached = $this->cards->create(12, 0, Zone::Attached, $host->id, 1);

        $this->cards->removeFromGame([$host]);

        self::assertSame(Zone::Removed, $this->cards->get($attached->id)->zone);
        self::assertSame(2, $this->cards->count(Zone::Removed));
    }

    public function testMovingNon046DoesNotMoveAttachedLookalike(): void
    {
        // 046 以外のカードに付加カードは付かない（attachedTo は 046 だけ見る）
        $other = $this->cards->create(5, 0, Zone::Prep, self::P1, 1);
        $this->cards->create(12, 0, Zone::Attached, $other->id, 1);

        self::assertSame([], $this->cards->attachedTo($other));
    }

    public function testReturnToDeckWith046ReassignsIdsIncludingAttached(): void
    {
        $this->makeDeck(self::P1, 2);
        $host = $this->cards->create(CardRepository::ATTACH_HOST_CARD_NO, 0, Zone::Prep, self::P1, 1);
        $attached = $this->cards->create(12, 0, Zone::Attached, $host->id, 1);
        $plain = $this->cards->create(5, 0, Zone::Prep, self::P1, 2);

        $map = $this->cards->returnToDeck([$host, $plain], self::P1, true);

        self::assertEqualsCanonicalizing([$host->id, $attached->id, $plain->id], array_keys($map));
        self::assertSame(5, $this->cards->count(Zone::Deck, self::P1));
        self::assertSame(0, $this->cards->count(Zone::Prep, self::P1));
        self::assertSame(0, $this->cards->count(Zone::Attached));
        $orders = array_map(static fn(Card $c): int => $c->order, $this->cards->list(Zone::Deck, self::P1));
        self::assertSame(range(0, 4), $orders);
    }

    public function testDetachMovesCardOut(): void
    {
        $host = $this->cards->create(CardRepository::ATTACH_HOST_CARD_NO, 0, Zone::Prep, self::P1, 1);
        $attached = $this->cards->create(12, 0, Zone::Attached, $host->id, 1);

        $this->cards->detach($attached, Zone::Acquired, self::P1);

        self::assertSame(Zone::Acquired, $this->cards->get($attached->id)->zone);
        self::assertSame([], $this->cards->attachedTo($host));
    }

    public function testSidewaysIsClearedOnMove(): void
    {
        $card = $this->cards->create(5, 0, Zone::Prep, self::P1, 1);
        $this->cards->setSideways($card, true);
        self::assertTrue($this->cards->get($card->id)->sideways);

        $this->cards->move($card, Zone::Deck, self::P1);

        self::assertFalse($this->cards->get($card->id)->sideways);
    }

    public function testRowFillSlotsReturnsLowestFreeSlots(): void
    {
        $this->cards->create(5, 0, Zone::Row, 2, 0);
        $this->cards->create(6, 0, Zone::Row, 2, 2);
        $this->cards->create(7, 0, Zone::Row, 4, 0);

        self::assertSame([1, 3], $this->cards->rowFillSlots(2, 2));
        self::assertSame([1, 2, 3], $this->cards->rowFillSlots(4, 3));
        self::assertSame([0], $this->cards->rowFillSlots(6, 1));
    }

    public function testDeckViewForOwnerIsSortedByCardNoAndIncludesAttached(): void
    {
        $this->cards->create(9, 0, Zone::Deck, self::P1, 0);
        $this->cards->create(5, 0, Zone::Deck, self::P1, 1);
        $host = $this->cards->create(CardRepository::ATTACH_HOST_CARD_NO, 0, Zone::Prep, self::P1, 1);
        $this->cards->create(12, 0, Zone::Attached, $host->id, 1);
        $this->cards->create(7, 0, Zone::Acquired, self::P1, 1);
        $this->cards->create(6, 0, Zone::Facedown, self::P1, 1);
        $this->cards->create(8, 0, Zone::Deck, self::P2, 0);

        $nos = array_map(static fn(Card $c): int => $c->cardNo, $this->cards->deckViewForOwner(self::P1));

        self::assertSame([5, 6, 7, 9, 12, 46], $nos);
    }

    public function testGetManyAndSetOrders(): void
    {
        $deck = $this->makeDeck(self::P1, 3);
        $ids = [$deck[2]->id, $deck[0]->id, $deck[1]->id];

        $this->cards->setOrders($ids);

        self::assertSame($ids, $this->ids(Zone::Deck, self::P1));
        self::assertCount(2, $this->cards->getMany([$deck[0]->id, $deck[1]->id]));
        self::assertSame([], $this->cards->getMany([]));
    }

    public function testPlayerValuesRoundTrip(): void
    {
        $this->addPlayers([self::P1, self::P2]);

        $this->players->setPrepFinished(self::P1, true);
        $this->players->setPrepChoice(self::P1, PrepChoice::Draw);
        $this->players->setHalved(self::P2, true);
        $this->players->setExploreOutput(self::P2, 7);

        self::assertTrue($this->players->prepFinished(self::P1));
        self::assertFalse($this->players->prepFinished(self::P2));
        self::assertSame(PrepChoice::Draw, $this->players->prepChoice(self::P1));
        self::assertNull($this->players->prepChoice(self::P2));
        self::assertTrue($this->players->isHalved(self::P2));
        self::assertSame(7, $this->players->exploreOutput(self::P2));
        self::assertSame([self::P1 => true, self::P2 => false], $this->players->prepFinishedMap());
        self::assertSame([self::P1 => true, self::P2 => false], $this->players->prepChosenMap());

        $this->players->clearPrepChoices();
        self::assertSame([self::P1 => false, self::P2 => false], $this->players->prepChosenMap());

        $this->players->setExploreOutput(self::P2, null);
        $this->players->resetRoundValues();
        self::assertFalse($this->players->prepFinished(self::P1));
        self::assertFalse($this->players->isHalved(self::P2));
        self::assertNull($this->players->exploreOutput(self::P2));
    }
}
