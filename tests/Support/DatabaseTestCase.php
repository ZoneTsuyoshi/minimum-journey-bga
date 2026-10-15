<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Support;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PlayerValues;
use Bga\Games\MinimumJourney\Data\Zone;
use PHPUnit\Framework\TestCase;

/**
 * 各テストの前にテーブルを作り直し、データ層を実 DB（MariaDB）につなぐ。
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PdoDatabase $db;
    protected CardRepository $cards;
    protected ArrayGlobals $rawGlobals;
    protected GlobalValues $globals;
    protected PlayerValues $players;
    protected SeededRandom $rand;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::connectFromEnv();
        Schema::reset($this->db);
        $this->rand = new SeededRandom(1);
        $this->cards = new CardRepository($this->db, $this->rand);
        $this->rawGlobals = new ArrayGlobals();
        $this->globals = new GlobalValues($this->rawGlobals);
        $this->players = new PlayerValues($this->db);
    }

    /**
     * @param list<int> $ids
     */
    protected function addPlayers(array $ids): void
    {
        foreach ($ids as $no => $id) {
            $this->db->execute("INSERT INTO `player` (`player_id`, `player_no`) VALUES ($id, " . ($no + 1) . ')');
        }
    }

    protected function countAll(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM `card`');
    }

    /**
     * @return list<int> ID を order の昇順で
     */
    protected function ids(Zone $zone, ?int $arg = null): array
    {
        return array_map(static fn(Card $c): int => $c->id, $this->cards->list($zone, $arg));
    }
}
