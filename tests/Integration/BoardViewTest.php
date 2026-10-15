<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\BoardView;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;
use MinimumJourneyTests\Support\SeededRandom;

/**
 * getAllDatas の盤面部分（BoardView）が、セットアップ直後の公開情報だけを返すことを確かめる。
 */
final class BoardViewTest extends DatabaseTestCase
{
    public function testBoardAfterSetupShowsOnlyPublicInformation(): void
    {
        $ids = [2310001, 2310002, 2310003];
        $this->addPlayers($ids);
        $rand = new SeededRandom(7);
        $repo = new CardRepository($this->db, $rand);
        (new GameSetup($repo, $this->globals, $rand))->run($ids);

        $board = (new BoardView($repo, $this->globals, $this->players))->build($ids);

        self::assertSame(0, $board['round']);
        self::assertSame($ids, $board['playerOrder']);
        self::assertSame(
            [2 => 10, 4 => 10, 6 => 8, 8 => 6],
            $board['piles'],
        );
        self::assertSame(3, count($board['rows'][2]));
        self::assertSame(2, count($board['rows'][8]));
        self::assertNotNull($board['unknown']);
        self::assertContains($board['charm']['holder'], $ids);
        foreach ($ids as $id) {
            self::assertSame(4, $board['players'][$id]['deckCount']);
            self::assertSame(0, $board['players'][$id]['facedownCount']);
        }

        // 山札・探査山札・除外の中身（ID）が JSON に出ない。公開してよいのは列・未確認帯域・お守りのカードだけ
        $public = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            foreach ($repo->list(Zone::Row, $cost) as $card) {
                $public[] = $card->id;
            }
        }
        $public[] = $board['unknown']['id'];
        $public[] = $board['charm']['card']['id'];
        $json = json_encode($board, JSON_THROW_ON_ERROR);
        preg_match_all('/"id":(\d+)/', $json, $matches);
        self::assertEqualsCanonicalizing($public, array_map('intval', $matches[1]));
        self::assertStringNotContainsString('prep_choice', $json);
    }

    public function testPrepAndAcquiredAreListedAfterDrawAndExplore(): void
    {
        $ids = [2310001, 2310002, 2310003];
        $this->addPlayers($ids);
        $rand = new SeededRandom(11);
        $repo = new CardRepository($this->db, $rand);
        (new GameSetup($repo, $this->globals, $rand))->run($ids);
        $view = new BoardView($repo, $this->globals, $this->players);

        $p = $ids[0];
        $first = $repo->drawTopToPrep($p);
        $second = $repo->drawTopToPrep($p);
        self::assertNotNull($first);
        self::assertNotNull($second);
        $repo->setSideways($second, true);
        $hidden = $repo->drawTopToFacedown($p);
        self::assertNotNull($hidden);

        $row = $repo->list(Zone::Row, 2)[0];
        $repo->moveToAcquired($row, $p);

        $board = $view->build($ids);
        $mine = $board['players'][$p];
        self::assertSame(2, $mine['prepCount']);
        self::assertSame([$first->id, $second->id], array_map(static fn(array $e): int => $e['card']['id'], $mine['prep']));
        self::assertSame([false, true], array_map(static fn(array $e): bool => $e['sideways'], $mine['prep']));
        self::assertSame($first->cardNo, $mine['prep'][0]['card']['cardNo']);
        self::assertSame($first->art, $mine['prep'][0]['card']['art']);
        self::assertGreaterThan($mine['prep'][0]['order'], $mine['prep'][1]['order']);
        self::assertSame([], $mine['prep'][0]['attached']);
        self::assertSame([$row->id], array_map(static fn(array $c): int => $c['id'], $mine['acquired']));
        self::assertSame([], $board['players'][$ids[1]]['prep']);
        self::assertSame([], $board['players'][$ids[1]]['acquired']);
        self::assertSame(1, $mine['facedownCount']);
        self::assertSame(2, count($board['rows'][2]));

        // 山札・裏向き・探査山札の ID は出ない
        $secret = [$hidden->id];
        foreach ($repo->list(Zone::Deck) as $card) {
            $secret[] = $card->id;
        }
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            foreach ($repo->list(Zone::ExploreDeck, $cost) as $card) {
                $secret[] = $card->id;
            }
        }
        $json = json_encode($board, JSON_THROW_ON_ERROR);
        preg_match_all('/"id":(\d+)/', $json, $matches);
        self::assertSame([], array_values(array_intersect($secret, array_map('intval', $matches[1]))));
    }

    public function testAttachedCardIsListedUnderItsHost(): void
    {
        $ids = [2310001, 2310002, 2310003];
        $this->addPlayers($ids);
        $rand = new SeededRandom(5);
        $repo = new CardRepository($this->db, $rand);
        (new GameSetup($repo, $this->globals, $rand))->run($ids);

        $host = $repo->create(46, 0, Zone::Prep, $ids[0], 9);
        $extra = $repo->create(3, 0, Zone::Deck, $ids[0]);
        $repo->attach($host, $extra);

        $board = (new BoardView($repo, $this->globals, $this->players))->build($ids);
        $entry = $board['players'][$ids[0]]['prep'][0];
        self::assertSame($host->id, $entry['card']['id']);
        self::assertSame([$extra->id], array_map(static fn(array $c): int => $c['id'], $entry['attached']));
        self::assertSame(1, $board['players'][$ids[0]]['prepCount']);
    }
}
