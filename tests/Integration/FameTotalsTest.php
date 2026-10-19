<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\FameTotals;
use Bga\Games\MinimumJourney\Flow\FinalScorer;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;

/**
 * 名声合計の表示（ゲームオプション 101。007 §11.3）で送る値。最終得点と同じ計算になること。
 */
final class FameTotalsTest extends DatabaseTestCase
{
    private const PLAYER_IDS = [2330001, 2330002, 2330003];

    private function start(): FameTotals
    {
        $this->addPlayers(self::PLAYER_IDS);
        (new GameSetup($this->cards, $this->globals, $this->rand))->run(self::PLAYER_IDS, null);
        return new FameTotals($this->cards, $this->globals);
    }

    public function testStartingTotalsAreTheStarterDeckPlusCharm(): void
    {
        $totals = $this->start()->totals(self::PLAYER_IDS);

        $starter = 3 + 2 + 2 + 1; // 国旗 + 乗組員 2 枚 + 初期探査船
        $charm = $this->cards->list(Zone::Charm)[0];
        foreach (self::PLAYER_IDS as $playerId) {
            self::assertSame($starter + ($charm->locationArg === $playerId ? 1 : 0), $totals[$playerId]);
        }
    }

    public function testDeckCardsListsTheSameCardsAsDeckCardNosSortedByCardNo(): void
    {
        $fame = $this->start();
        [$a] = self::PLAYER_IDS;
        $this->cards->moveToAcquired($this->cards->list(Zone::Row, 2)[0], $a);
        $this->cards->drawTopToPrep($a);

        $cards = $fame->deckCards($a);
        $nos = array_column($cards, 'cardNo');
        $expected = $fame->deckCardNos($a);
        sort($expected);
        self::assertSame($expected, $nos);
        self::assertSame(['id', 'cardNo', 'art'], array_keys($cards[0]));
        self::assertCount(count(array_unique(array_column($cards, 'id'))), $cards, 'ID は重複しない');
    }

    public function testTotalsFollowAcquisitionRemovalAndMatchFinalScoring(): void
    {
        $fame = $this->start();
        [$a, $b] = self::PLAYER_IDS;
        $before = $fame->totals(self::PLAYER_IDS);

        $unknown = $this->cards->list(Zone::Unknown)[0];
        $this->cards->moveToAcquired($unknown, $a);
        $this->globals->setUnknownAcquirer($a);
        $flag = array_values(array_filter(
            $this->cards->list(Zone::Deck, $b),
            static fn(Card $card): bool => $card->cardNo === 1,
        ))[0];
        $this->cards->removeFromGame([$flag]);

        $after = $fame->totals(self::PLAYER_IDS);
        self::assertSame($before[$a] + 10, $after[$a], '未確定帯域の名声 10 を足す');
        self::assertSame($before[$b] - 3, $after[$b], '除外した国旗の名声 3 を引く');
        self::assertSame(
            FinalScorer::score($fame->deckCardNos($a), $this->cards->list(Zone::Charm)[0]->locationArg === $a, true)->fame(),
            $after[$a],
            '最終得点と同じ計算',
        );
    }

    public function testOnlyFameChangingNotificationsTriggerAnUpdate(): void
    {
        self::assertTrue(FameTotals::affectedBy([new Notification(null, 'cardAcquired', '', [])]));
        self::assertTrue(FameTotals::affectedBy([new Notification(null, 'amuletMoved', '', [])]));
        self::assertTrue(FameTotals::affectedBy([new Notification(null, 'cardsRemoved', '', [])]));
        self::assertFalse(FameTotals::affectedBy([new Notification(null, 'cardDrawn', '', []), new Notification(null, 'countersUpdate', '', [])]));
    }
}
