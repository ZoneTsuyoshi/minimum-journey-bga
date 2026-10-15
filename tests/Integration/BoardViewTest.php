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
}
