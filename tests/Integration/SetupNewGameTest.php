<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\ResolveLevel;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;
use MinimumJourneyTests\Support\SeededRandom;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GameSetup（001 §3、007 §5.3）を実 DB で動かし、結果の盤面を確かめる。
 */
final class SetupNewGameTest extends DatabaseTestCase
{
    private const PLAYER_IDS = [2310001, 2310002, 2310003, 2310004];

    /** 人数 => コスト => 探査山札に残る枚数（除外と列の公開の後） */
    private const PILE_AFTER = [
        3 => [2 => 10, 4 => 10, 6 => 8, 8 => 6],
        4 => [2 => 11, 4 => 12, 6 => 10, 8 => 8],
    ];

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function playersAndSeeds(): iterable
    {
        foreach ([3, 4] as $count) {
            foreach ([1, 2, 3, 42, 777] as $seed) {
                yield "$count players, seed $seed" => [$count, $seed];
            }
        }
    }

    /**
     * @return list<int>
     */
    private function runSetup(int $count, int $seed, ?int $charmOption = null): array
    {
        $ids = array_slice(self::PLAYER_IDS, 0, $count);
        $this->addPlayers($ids);
        $rand = new SeededRandom($seed);
        $repo = new CardRepository($this->db, $rand);
        (new GameSetup($repo, $this->globals, $rand))->run($ids, $charmOption);
        return $ids;
    }

    #[DataProvider('playersAndSeeds')]
    public function testCardCountsAndZones(int $count, int $seed): void
    {
        $ids = $this->runSetup($count, $seed);

        // 初期カード 4 枚 × 人数 + 漂流カード 52 + 056 + お守り 1
        self::assertSame(4 * $count + 52 + 1 + 1, $this->countAll());
        self::assertSame($count === 3 ? 8 : 0, $this->cards->count(Zone::SetupRemoved));
        self::assertSame(1, $this->cards->count(Zone::Unknown));
        self::assertSame(1, $this->cards->count(Zone::Charm));

        foreach ($ids as $id) {
            self::assertSame(4, $this->cards->count(Zone::Deck, $id));
        }
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            $row = GameSetup::ROW_SIZES[$count][$cost];
            self::assertSame($row, $this->cards->count(Zone::Row, $cost), "row $cost");
            self::assertSame(self::PILE_AFTER[$count][$cost], $this->cards->count(Zone::ExploreDeck, $cost), "pile $cost");
            // 列はスロット 0 から連続
            self::assertSame(
                range(0, $row - 1),
                array_map(static fn(Card $c): int => $c->order, $this->cards->list(Zone::Row, $cost)),
            );
        }
        // 除外は各コスト 2 枚ずつ
        if ($count === 3) {
            $byCost = [];
            foreach ($this->cards->list(Zone::SetupRemoved) as $card) {
                $cost = CardDefinitions::get(sprintf('%03d', $card->cardNo))?->cost;
                $byCost[$cost] = ($byCost[$cost] ?? 0) + 1;
            }
            ksort($byCost);
            self::assertSame([2 => 2, 4 => 2, 6 => 2, 8 => 2], $byCost);
        }
    }

    #[DataProvider('playersAndSeeds')]
    public function testEveryDriftCardExistsOnceAndCostsMatch(int $count, int $seed): void
    {
        $this->runSetup($count, $seed);

        $seen = [];
        foreach ([Zone::ExploreDeck, Zone::Row, Zone::SetupRemoved] as $zone) {
            foreach ($this->cards->list($zone) as $card) {
                $seen[] = $card->cardNo;
                if ($zone !== Zone::SetupRemoved) {
                    $definition = CardDefinitions::get(sprintf('%03d', $card->cardNo));
                    self::assertSame($definition?->cost, $card->locationArg, "card {$card->cardNo} in {$zone->value}");
                }
                self::assertSame(0, $card->art);
            }
        }
        sort($seen);
        self::assertSame(range(4, 55), $seen);

        $unknown = $this->cards->list(Zone::Unknown)[0];
        self::assertSame(56, $unknown->cardNo);
        self::assertSame(0, $unknown->locationArg);
    }

    #[DataProvider('playersAndSeeds')]
    public function testStarterDecksHaveNoDuplicateArt(int $count, int $seed): void
    {
        $ids = $this->runSetup($count, $seed);

        $artsByNo = [1 => [], 2 => [], 3 => []];
        foreach ($ids as $id) {
            $deck = $this->cards->list(Zone::Deck, $id);
            $nos = array_map(static fn(Card $c): int => $c->cardNo, $deck);
            sort($nos);
            self::assertSame([1, 2, 2, 3], $nos);
            // シャッフルで order が 0〜3 に振り直されている
            $orders = array_map(static fn(Card $c): int => $c->order, $deck);
            sort($orders);
            self::assertSame([0, 1, 2, 3], $orders);
            foreach ($deck as $card) {
                $max = CardDefinitions::get(sprintf('%03d', $card->cardNo))?->artCount;
                self::assertGreaterThanOrEqual(1, $card->art);
                self::assertLessThanOrEqual($max, $card->art);
                $artsByNo[$card->cardNo][] = $card->art;
            }
        }
        // (card_no, card_art) は卓内で一意（007 §3.1）
        foreach ($artsByNo as $no => $arts) {
            self::assertSame(array_values(array_unique($arts)), $arts, "card $no arts");
            self::assertCount($no === 2 ? $count * 2 : $count, $arts);
        }
    }

    #[DataProvider('playersAndSeeds')]
    public function testIdsAreUniqueAndNotInCreationOrder(int $count, int $seed): void
    {
        $this->runSetup($count, $seed);

        $ids = array_map('intval', array_column($this->db->rows('SELECT `card_id` FROM `card`'), 'card_id'));
        self::assertCount($this->countAll(), array_unique($ids));
        // 探査山札の order と ID の昇順が、すべての山で一致してしまうことはない
        $sameOrder = 0;
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            $byOrder = $this->ids(Zone::ExploreDeck, $cost);
            $sorted = $byOrder;
            sort($sorted);
            $sameOrder += $byOrder === $sorted ? 1 : 0;
        }
        self::assertLessThan(4, $sameOrder);
    }

    public function testGlobalsAreInitialized(): void
    {
        $this->runSetup(4, 5);

        self::assertSame(0, $this->globals->roundNo());
        self::assertSame(GlobalValues::PHASE_PREP, $this->globals->phase());
        self::assertSame([], $this->globals->prepSelectors());
        self::assertNull($this->globals->prepLatePlayer());
        self::assertSame(GlobalValues::emptyNextChoiceRecords(), $this->globals->nextChoiceRecords());
        self::assertSame(ResolveLevel::Init, $this->globals->resolveLevel());
        self::assertNull($this->globals->exploreState());
        self::assertFalse($this->globals->gameEndTriggered());
        self::assertNull($this->globals->endReason());
        self::assertNull($this->globals->unknownAcquirer());
        self::assertSame(8, $this->globals->pile8Initial());
        self::assertSame(11 + 12 + 10 + 8, $this->globals->pilesInitialTotal());
    }

    public function testPlayerValuesStartAtDefaults(): void
    {
        $ids = $this->runSetup(3, 5);

        foreach ($ids as $id) {
            self::assertFalse($this->players->prepFinished($id));
            self::assertNull($this->players->prepChoice($id));
            self::assertFalse($this->players->isHalved($id));
            self::assertNull($this->players->exploreOutput($id));
        }
    }

    public function testPileTotalsFor3Players(): void
    {
        $this->runSetup(3, 5);

        self::assertSame(6, $this->globals->pile8Initial());
        self::assertSame(10 + 10 + 8 + 6, $this->globals->pilesInitialTotal());
    }

    public function testCharmFaceFollowsOption(): void
    {
        $this->runSetup(4, 1, null);
        self::assertSame(57, $this->cards->list(Zone::Charm)[0]->cardNo);

        $this->setUp();
        $this->runSetup(4, 1, GameSetup::CHARM_SAFE_PASSAGE);
        self::assertSame(57, $this->cards->list(Zone::Charm)[0]->cardNo);

        $this->setUp();
        $this->runSetup(4, 1, GameSetup::CHARM_MISFORTUNE_WARDING);
        $charms = $this->cards->list(Zone::Charm);
        self::assertCount(1, $charms);
        self::assertSame(58, $charms[0]->cardNo);
    }

    public function testCharmHolderIsRandomPlayer(): void
    {
        $holders = [];
        for ($seed = 1; $seed <= 40; $seed++) {
            $this->setUp();
            $ids = $this->runSetup(3, $seed);
            $holder = $this->cards->list(Zone::Charm)[0]->locationArg;
            self::assertContains($holder, $ids);
            $holders[$holder] = true;
        }
        self::assertCount(3, $holders, 'every seat should become the holder for some seed');
    }

    public function testTwoPlayersAreRejected(): void
    {
        $this->addPlayers([1, 2]);
        $this->expectException(\InvalidArgumentException::class);
        (new GameSetup($this->cards, $this->globals, $this->rand))->run([1, 2]);
    }

    public function testShuffleChangesDeckOrderAcrossSeeds(): void
    {
        $orders = [];
        for ($seed = 1; $seed <= 20; $seed++) {
            $this->setUp();
            $ids = $this->runSetup(4, $seed);
            $orders[] = implode(',', array_map(
                static fn(Card $c): int => $c->cardNo,
                $this->cards->list(Zone::Deck, $ids[0]),
            ));
        }
        self::assertGreaterThan(3, count(array_unique($orders)));
    }
}
