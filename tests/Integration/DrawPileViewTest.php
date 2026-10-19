<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\BoardView;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\DrawPileView;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;

/**
 * 山札の一覧（ゲームオプション 102。007 §13.1）。カード番号順で順序が漏れず、裏向きの山と他人の山札を含まないこと。
 */
final class DrawPileViewTest extends DatabaseTestCase
{
    private const PLAYER_IDS = [2340001, 2340002, 2340003];

    private function start(): void
    {
        $this->addPlayers(self::PLAYER_IDS);
        (new GameSetup($this->cards, $this->globals, $this->rand))->run(self::PLAYER_IDS, null);
    }

    public function testListIsSortedByCardNoThenIdAndCoversTheWholeDrawPile(): void
    {
        $this->start();
        [$a] = self::PLAYER_IDS;
        $cards = DrawPileView::cards($this->cards, $a);

        self::assertCount($this->cards->count(Zone::Deck, $a), $cards);
        self::assertSame(['id', 'cardNo', 'art'], array_keys($cards[0]));
        $keys = array_map(static fn(array $c): array => [$c['cardNo'], $c['id']], $cards);
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys, 'カード番号、ID の昇順');
        self::assertEqualsCanonicalizing(
            array_map(static fn($c): int => $c->id, $this->cards->list(Zone::Deck, $a)),
            array_column($cards, 'id'),
        );
    }

    public function testListDoesNotDependOnThePileOrder(): void
    {
        $this->start();
        [$a] = self::PLAYER_IDS;
        $before = DrawPileView::cards($this->cards, $a);
        $this->cards->shuffleDeck($a);
        self::assertSame($before, DrawPileView::cards($this->cards, $a));
    }

    public function testFacedownAndPrepCardsAreExcludedAndFollowTheDrawPile(): void
    {
        $this->start();
        [$a, $b] = self::PLAYER_IDS;
        $all = $this->cards->list(Zone::Deck, $a);
        $facedown = $this->cards->drawTopToFacedown($a);
        $prep = $this->cards->drawTopToPrep($a);

        $ids = array_column(DrawPileView::cards($this->cards, $a), 'id');
        self::assertCount(count($all) - 2, $ids);
        self::assertNotContains($facedown->id, $ids, '裏向きの山は本人にも見せない');
        self::assertNotContains($prep->id, $ids);

        $this->cards->returnToDeck([$prep], $a, false);
        // 山札に戻すと ID が付け替わるので、枚数で確かめる
        self::assertCount(count($all) - 1, DrawPileView::cards($this->cards, $a));

        $otherIds = array_column(DrawPileView::cards($this->cards, $b), 'id');
        self::assertSame([], array_intersect($ids, $otherIds), '他人の山札は混ざらない');
    }

    public function testBoardViewNeverCarriesThePileContents(): void
    {
        $this->start();
        $board = (new BoardView($this->cards, $this->globals, $this->players))->build(self::PLAYER_IDS);

        foreach ($board['players'] as $player) {
            self::assertArrayNotHasKey('myDrawPile', $player);
            self::assertArrayNotHasKey('drawPile', $player);
        }
        self::assertArrayNotHasKey('myDrawPile', $board, '山札の一覧は Game.php が本人にだけ足す');
    }
}
