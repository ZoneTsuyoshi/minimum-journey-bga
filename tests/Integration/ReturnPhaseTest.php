<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Flow\ExplorePhase;
use Bga\Games\MinimumJourney\Flow\FameModifiers;
use Bga\Games\MinimumJourney\Flow\FinalScorer;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Flow\ReturnPhase;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;
use MinimumJourneyTests\Support\SeededRandom;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 帰還フェイズ（状態 50・52・53）と最終得点（60）を、セットアップ後の実 DB で動かす。
 * 最後のテストは準備 → 探査 → 帰還を、ゲームが終わるまで複数ラウンド回す。
 */
final class ReturnPhaseTest extends DatabaseTestCase
{
    private const PLAYER_IDS = [2320001, 2320002, 2320003, 2320004];

    /** @var list<int> */
    private array $ids = [];
    private ReturnPhase $phase;
    private ExplorePhase $explore;

    /**
     * @return iterable<string, array{int}>
     */
    public static function playerCounts(): iterable
    {
        yield '3 players' => [3];
        yield '4 players' => [4];
    }

    private function start(int $count, ?int $seed = null): void
    {
        if ($seed !== null) {
            $this->rand = new SeededRandom($seed);
            $this->cards = new CardRepository($this->db, $this->rand);
        }
        $this->ids = array_slice(self::PLAYER_IDS, 0, $count);
        $this->addPlayers($this->ids);
        (new GameSetup($this->cards, $this->globals, $this->rand))->run($this->ids, null);
        $name = static fn(int $id): string => 'P' . $id;
        $this->phase = new ReturnPhase($this->cards, $this->players, $this->globals, $name);
        $this->explore = new ExplorePhase(
            $this->cards,
            $this->players,
            $this->globals,
            new ExploreOutputCalculator($this->cards, $this->globals),
            $name,
        );
        $this->globals->setRoundNo(1);
    }

    private function charm(): Card
    {
        return $this->cards->list(Zone::Charm)[0];
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
     * @param array<int, int> $outputs
     * @param list<int> $order
     */
    private function setOutputs(array $outputs, array $order): void
    {
        foreach ($outputs as $id => $output) {
            $this->players->setExploreOutput($id, $output);
        }
        $this->globals->setExploreOrder($order);
    }

    // ---- 50: お守りの移動 ----

    #[DataProvider('playerCounts')]
    public function testCharmMovesToTheLowestOutput(int $count): void
    {
        $this->start($count);
        [$a, , $c] = $this->ids;
        $this->cards->move($this->charm(), Zone::Charm, $a, 0);
        $outputs = array_fill_keys($this->ids, 9);
        $outputs[$c] = 2;
        $this->setOutputs($outputs, $this->ids);

        $notes = $this->phase->moveCharm($this->ids);

        self::assertSame($c, $this->charm()->locationArg);
        self::assertSame(GlobalValues::PHASE_RETURN, $this->globals->phase());
        $moved = self::named($notes, 'amuletMoved');
        self::assertCount(1, $moved);
        self::assertSame($c, $moved[0]->args['player_id']);
        self::assertSame($a, $moved[0]->args['from_player_id']);
        self::assertSame($this->charm()->id, $moved[0]->args['card']['id']);
        self::assertSame(['Safe Passage Charm'], [$moved[0]->args['card_name']]);
    }

    public function testTieGoesToTheLaterExplorerAndNoNoteWhenUnchanged(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $this->cards->move($this->charm(), Zone::Charm, $a, 0);
        $this->setOutputs([$a => 4, $b => 4, $c => 7], [$c, $a, $b]);

        $this->phase->moveCharm($this->ids);
        self::assertSame($b, $this->charm()->locationArg, '同点は手番が遅い b');

        // もう一度（所有者 b のまま）。通知は出さない
        self::assertSame([], $this->phase->moveCharm($this->ids));
        self::assertSame($b, $this->charm()->locationArg);
    }

    // ---- 52: シャッフル ----

    #[DataProvider('playerCounts')]
    public function testShuffleGathersEverythingIntoTheDecks(int $count): void
    {
        $this->start($count);
        [$a, $b] = $this->ids;
        // a: 準備場 3 枚（うち 1 枚は横向き）、獲得 1 枚、裏向き 1 枚
        $prep = [$this->cards->drawTopToPrep($a), $this->cards->drawTopToPrep($a), $this->cards->drawTopToPrep($a)];
        $this->cards->setSideways($prep[0] ?? throw new \LogicException(), true);
        $this->cards->drawTopToFacedown($a);
        $acquired = $this->cards->peekTop(Zone::ExploreDeck, 2, 1)[0];
        $this->cards->moveToAcquired($acquired, $a);
        $removed = $this->cards->peekTop(Zone::ExploreDeck, 4, 1)[0];
        $this->cards->removeFromGame([$removed]);
        $this->players->setHalved($b, true);
        $this->players->setExploreOutput($b, 5);
        $this->globals->setExploreOrder($this->ids);
        $total = $this->countAll();
        $aCardNos = $this->cardNos($a);

        $notes = $this->phase->shuffle($this->ids);

        self::assertSame($total, $this->countAll());
        self::assertSame(4 + 1, $this->cards->count(Zone::Deck, $a), '初期 4 + 獲得 1');
        foreach ([Zone::Prep, Zone::Facedown, Zone::Acquired, Zone::Attached] as $zone) {
            self::assertSame(0, $this->cards->count($zone), $zone->value);
        }
        $aAfter = $this->cardNos($a);
        sort($aCardNos);
        sort($aAfter);
        self::assertSame($aCardNos, $aAfter, '名声計算の対象は変わらない');
        foreach ($this->cards->list(Zone::Deck, $a) as $card) {
            self::assertFalse($card->sideways, '横向きは戻る');
        }
        self::assertSame(1, $this->cards->count(Zone::Removed), '除外は戻らない');

        // ラウンド単位の値のリセット
        self::assertFalse($this->players->isHalved($b));
        self::assertNull($this->players->exploreOutput($b));
        self::assertSame([], $this->globals->exploreOrder());

        // 通知: 古い ID（表向きだった 3 + 1 枚）だけ。裏向きの ID は載せない
        $gathered = self::named($notes, 'cardsGatheredToDecks');
        self::assertCount(1, $gathered);
        $oldIds = $gathered[0]->args['gathered'][$a];
        self::assertCount(4, $oldIds);
        foreach ($oldIds as $old) {
            self::assertFalse($this->exists($old), '古い ID は付け替えられて消えている');
        }
        self::assertSame($this->cards->count(Zone::Deck, $a), $gathered[0]->args['counts'][$a]);
        self::assertSame([], $gathered[0]->args['gathered'][$b]);
    }

    public function testShuffleBringsTheAttachedCardOfCard046BackAsASeparateCard(): void
    {
        $this->start(3);
        $a = $this->ids[0];
        $host = $this->cards->create(46, 0, Zone::Prep, $a, 1);
        $attached = $this->cards->drawTopToPrep($a) ?? throw new \LogicException();
        $this->cards->attach($host, $attached);
        $before = $this->cards->count(Zone::Deck, $a);

        $notes = $this->phase->shuffle($this->ids);

        self::assertSame($before + 2, $this->cards->count(Zone::Deck, $a), '046 の付加カードも別のカードとして山札へ');
        self::assertSame(0, $this->cards->count(Zone::Attached));
        self::assertCount(2, self::named($notes, 'cardsGatheredToDecks')[0]->args['gathered'][$a]);
    }

    public function testShuffleChangesTheOrderOfTheDeck(): void
    {
        $this->start(3);
        $a = $this->ids[0];
        $seen = [];
        for ($i = 0; $i < 6; $i++) {
            $this->phase->shuffle($this->ids);
            $seen[implode(',', array_map(fn(Card $c): int => $c->cardNo, $this->cards->list(Zone::Deck, $a)))] = true;
        }
        self::assertGreaterThan(1, count($seen));
    }

    // ---- 53: 補充 ----

    #[DataProvider('playerCounts')]
    public function testRefillFillsRowsToTheRegularSizeAndGoesToTheNextRound(int $count): void
    {
        $this->start($count);
        $sizes = GameSetup::ROW_SIZES[$count];
        // 2 コストの列から 2 枚、8 コストの列から 1 枚が取られた
        $row2 = $this->cards->list(Zone::Row, 2);
        $this->cards->moveToAcquired($row2[0], $this->ids[0]);
        $this->cards->moveToAcquired($row2[2], $this->ids[0]);
        $this->cards->moveToAcquired($this->cards->list(Zone::Row, 8)[1], $this->ids[0]);
        $deck2 = $this->cards->count(Zone::ExploreDeck, 2);
        $deck8 = $this->cards->count(Zone::ExploreDeck, 8);

        $result = $this->phase->refill($this->ids);

        self::assertSame('nextRound', $result->transition);
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            self::assertSame($sizes[$cost], $this->cards->count(Zone::Row, $cost), "cost $cost");
        }
        self::assertSame($deck2 - 2, $this->cards->count(Zone::ExploreDeck, 2));
        self::assertSame($deck8 - 1, $this->cards->count(Zone::ExploreDeck, 8));
        $slots = array_map(static fn(Card $c): int => $c->order, $this->cards->list(Zone::Row, 2));
        self::assertSame(range(0, $sizes[2] - 1), $slots, '取られた位置から埋める');

        $notes = self::named($result->notifications, 'rowRefilled');
        self::assertCount(2, $notes, '補充のあったコストだけ');
        self::assertSame(2, $notes[0]->args['cost']);
        self::assertSame([0, 2], array_map(static fn(array $e): int => $e['slot'], $notes[0]->args['cards']));
        self::assertSame(8, $notes[1]->args['cost']);
        self::assertFalse($this->globals->gameEndTriggered());
    }

    public function testRefillWithShortPileFillsWhatIsLeftAndLeavesTheRestEmpty(): void
    {
        $this->start(4);
        $this->emptyPile(4, 1);
        foreach ($this->cards->list(Zone::Row, 4) as $card) {
            $this->cards->moveToAcquired($card, $this->ids[0]);
        }

        $result = $this->phase->refill($this->ids);

        self::assertSame(1, $this->cards->count(Zone::Row, 4), '山札のあるだけ');
        self::assertSame(0, $this->cards->count(Zone::ExploreDeck, 4));
        self::assertSame('nextRound', $result->transition, 'コスト 4 が尽きても終了条件ではない');
    }

    #[DataProvider('playerCounts')]
    public function testRefillThatEmptiesThePile8EndsTheGame(int $count): void
    {
        $this->start($count);
        $this->emptyPile(8, 1);
        $this->cards->moveToAcquired($this->cards->list(Zone::Row, 8)[0], $this->ids[0]);

        $result = $this->phase->refill($this->ids);

        self::assertSame(0, $this->cards->count(Zone::ExploreDeck, 8));
        self::assertSame('end', $result->transition);
        self::assertTrue($this->globals->gameEndTriggered());
        self::assertSame(GlobalValues::END_PILE8, $this->globals->endReason());
        $ended = self::named($result->notifications, 'gameEndTriggered');
        self::assertCount(1, $ended);
        self::assertSame('pile8', $ended[0]->args['reason']);
    }

    public function testEndFlagFromExplorationEndsTheGameAndKeepsTheFirstReason(): void
    {
        $this->start(3);
        $this->globals->setGameEndTriggered(true);
        $this->globals->setEndReason(GlobalValues::END_UNKNOWN);
        $this->emptyPile(8, 0);

        $result = $this->phase->refill($this->ids);

        self::assertSame('end', $result->transition);
        self::assertSame(GlobalValues::END_UNKNOWN, $this->globals->endReason(), '先に起きた理由を残す');
        self::assertSame([], self::named($result->notifications, 'gameEndTriggered'));
    }

    // ---- 60: 最終得点 ----

    public function testFinalScoringCountsDeckFameCharmAndTieBreakers(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $this->cards->move($this->charm(), Zone::Charm, $b, 0);
        $this->globals->setUnknownAcquirer($c);
        // c は未確認帯域（名声 10）を獲得。a は国旗（名声 3）を除外した
        $this->cards->moveToAcquired($this->cards->list(Zone::Unknown)[0], $c);
        $flag = array_values(array_filter(
            $this->cards->list(Zone::Deck, $a),
            static fn(Card $card): bool => $card->cardNo === 1,
        ))[0];
        $this->cards->removeFromGame([$flag]);

        $result = $this->phase->finalScoring($this->ids);

        $starter = 3 + 2 + 2 + 1;
        self::assertSame($starter - 3, $result->scores[$a]->fame(), '除外したカードは数えない');
        self::assertSame($starter, $result->scores[$b]->base);
        self::assertSame($starter + 1, $result->scores[$b]->fame(), 'お守りの所持者は +1（印字の名声は足さない）');
        self::assertSame($starter + 10, $result->scores[$c]->fame());
        self::assertTrue($result->scores[$c]->unknownAcquired);
        self::assertFalse($result->scores[$a]->unknownAcquired);
        self::assertGreaterThan($result->scores[$b]->aux, $result->scores[$c]->aux, '未確認帯域の獲得者が同点で上位');
        self::assertSame($result->scores[$a]->aux, $result->scores[$b]->aux);

        $notes = self::named($result->notifications, 'finalScore');
        self::assertCount(3, $notes);
        self::assertSame($this->ids, array_map(static fn(Notification $n): int => $n->args['player_id'], $notes), '席順');
        self::assertSame($starter + 1, $notes[1]->args['fame']);
        self::assertSame(1, $notes[1]->args['charm_bonus']);
        self::assertSame($starter, $notes[1]->args['base']);
        self::assertTrue($notes[2]->args['unknown_acquired']);
        self::assertNull($notes[0]->to, '全員に公開');
    }

    public function testFinalScoreNotificationPublishesWholeDeck(): void
    {
        $this->start(3);
        [$a, $b] = $this->ids;
        $this->cards->drawTopToPrep($a);
        $this->cards->drawTopToFacedown($a);
        $row = $this->cards->list(Zone::Row, 2)[0];
        $this->cards->moveToAcquired($row, $a);
        $host = $this->cards->create(46, 0, Zone::Prep, $a, 9);
        $this->cards->create(7, 0, Zone::Attached, $host->id, 1);
        $flag = array_values(array_filter(
            $this->cards->list(Zone::Deck, $b),
            static fn(Card $card): bool => $card->cardNo === 1,
        ))[0];
        $this->cards->removeFromGame([$flag]);

        $notes = self::named($this->phase->finalScoring($this->ids)->notifications, 'finalScore');

        $cardsA = $notes[0]->args['deck_cards'];
        self::assertCount($notes[0]->args['deck_size'], $cardsA, '一覧の枚数は deck_size と一致');
        self::assertCount(7, $cardsA, '初期 4 枚 + 獲得 1 + 046 + 付加カード');
        $nos = array_column($cardsA, 'cardNo');
        $sorted = $nos;
        sort($sorted);
        self::assertSame($sorted, $nos, 'カード番号の昇順');
        self::assertContains($row->cardNo, $nos);
        self::assertContains(46, $nos);
        self::assertContains(7, $nos);
        self::assertSame(['id', 'cardNo', 'art'], array_keys($cardsA[0]));
        self::assertCount(3, $notes[1]->args['deck_cards'], '初期 4 枚から除外した 1 枚を引く');
    }

    public function testFinalScoringCountsEveryDeckZoneOnce(): void
    {
        $this->start(3);
        $a = $this->ids[0];
        $this->cards->drawTopToPrep($a);
        $this->cards->drawTopToFacedown($a);
        $row = $this->cards->list(Zone::Row, 2)[0];
        $this->cards->moveToAcquired($row, $a);
        $host = $this->cards->create(46, 0, Zone::Prep, $a, 9);
        $this->cards->create(7, 0, Zone::Attached, $host->id, 1);

        $fame = static fn(int $no): int => CardDefinitions::get(sprintf('%03d', $no))->fame ?? 0;
        $expected = 8 + $fame($row->cardNo) + $fame(46) + $fame(7);
        $result = $this->phase->finalScoring($this->ids);

        self::assertSame($expected, $result->scores[$a]->base, '山札・準備場・獲得・裏向き・付加カードを 1 回ずつ');
    }

    // ---- 複数ラウンド ----

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function wholeGames(): iterable
    {
        foreach ([3, 4] as $count) {
            foreach ([1, 2, 3] as $seed) {
                yield "$count players, seed $seed" => [$count, $seed];
            }
        }
    }

    /**
     * 準備（山札から数枚を準備場へ・一部は裏向きへ）→ 探査（払える最も高いカードを獲得）→ 帰還を、ゲームが終わるまで回す。
     */
    #[DataProvider('wholeGames')]
    public function testPlayWholeGameUntilTheEndConditionIsMet(int $count, int $seed): void
    {
        $this->start($count, $seed * 7 + $count);
        $this->playUntilEnd($count);
    }

    private function playUntilEnd(int $count): void
    {
        $totalCards = $this->countAll();
        $rounds = 0;
        $transition = 'nextRound';
        while ($transition === 'nextRound') {
            $rounds++;
            self::assertLessThan(200, $rounds, 'ゲームが終わらない');
            $this->globals->setRoundNo($rounds);
            $this->prepareRound($rounds);
            $this->explore->start($this->ids);
            $this->exploreRound();

            $this->phase->moveCharm($this->ids);
            $this->phase->shuffle($this->ids);
            $transition = (string) $this->phase->refill($this->ids)->transition;

            self::assertSame($totalCards, $this->countAll(), 'カードが増減しない');
            foreach ($this->ids as $id) {
                self::assertSame(0, $this->cards->count(Zone::Prep, $id));
                self::assertSame(0, $this->cards->count(Zone::Acquired, $id));
                self::assertSame(0, $this->cards->count(Zone::Facedown, $id));
            }
            if ($transition === 'nextRound') {
                self::assertFalse($this->globals->gameEndTriggered());
                self::assertGreaterThan(0, $this->cards->count(Zone::ExploreDeck, 8), 'コスト 8 が残っている間は続く');
            }
        }

        self::assertTrue($this->globals->gameEndTriggered());
        $reason = $this->globals->endReason();
        if ($reason === GlobalValues::END_UNKNOWN) {
            self::assertNotNull($this->globals->unknownAcquirer());
            self::assertSame(0, $this->cards->count(Zone::Unknown));
        } else {
            self::assertSame(GlobalValues::END_PILE8, $reason);
            self::assertSame(0, $this->cards->count(Zone::ExploreDeck, 8));
        }

        $result = $this->phase->finalScoring($this->ids);
        self::assertCount($count, $result->scores);
        $holders = 0;
        foreach ($result->scores as $id => $score) {
            $holders += $score->charmBonus;
            self::assertGreaterThan(0, $score->fame());
            $expected = FinalScorer::baseFame($this->cardNos($id)) + FameModifiers::total($this->cardNos($id), $score->charmBonus === 1) + $score->charmBonus;
            self::assertSame($expected, $score->fame());
        }
        self::assertSame(1, $holders, 'お守りの所持者はちょうど 1 人');
    }

    private function prepareRound(int $round): void
    {
        foreach ($this->ids as $index => $id) {
            $draws = ($round + $index) % 4 + 1;
            for ($i = 0; $i < $draws; $i++) {
                $this->cards->drawTopToPrep($id);
            }
            if ($index % 2 === 0) {
                $this->cards->drawTopToFacedown($id);
            }
        }
    }

    private function exploreRound(): void
    {
        for ($turn = 0; $turn < count($this->ids); $turn++) {
            $begin = $this->explore->beginTurn();
            $playerId = $begin->playerId;
            self::assertNotNull($playerId);
            $targets = $this->explore->targets($playerId);

            $best = null;
            foreach ($targets['rowTargets'] as $t) {
                if ($best === null || $t['effectiveCost'] > $best['cost']) {
                    $best = ['source' => ExplorePhase::SOURCE_ROW, 'id' => $t['cardId'], 'cost' => $t['effectiveCost'], 'pile' => 0];
                }
            }
            foreach ($targets['deckTargets'] as $t) {
                if ($best === null || $t['effectiveCost'] > $best['cost']) {
                    $best = ['source' => ExplorePhase::SOURCE_DECK, 'id' => 0, 'cost' => $t['effectiveCost'], 'pile' => $t['cost']];
                }
            }
            if ($targets['unknownTarget'] !== null) {
                $best = ['source' => ExplorePhase::SOURCE_UNKNOWN, 'id' => $targets['unknownTarget']['cardId'], 'cost' => 99, 'pile' => 0];
            }
            if ($best !== null) {
                $this->explore->explore($playerId, $best['source'], $best['id'], $best['pile']);
            } else {
                $this->explore->pass($playerId);
            }
            $this->globals->setExploreIndex($this->globals->exploreIndex() + 1);
            $this->globals->setExploreState(null);
        }
    }

    // ---- ヘルパ ----

    /**
     * @return list<int> プレイヤーのデッキ（山札・準備場・獲得・裏向き）のカード番号
     */
    private function cardNos(int $playerId): array
    {
        $nos = [];
        foreach ([Zone::Deck, Zone::Prep, Zone::Acquired, Zone::Facedown] as $zone) {
            foreach ($this->cards->list($zone, $playerId) as $card) {
                $nos[] = $card->cardNo;
                foreach ($this->cards->attachedTo($card) as $attached) {
                    $nos[] = $attached->cardNo;
                }
            }
        }
        return $nos;
    }

    private function exists(int $cardId): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM `card` WHERE `card_id` = ' . $cardId) > 0;
    }

    /**
     * 探査山札を $keep 枚だけ残して残りを除外する。
     */
    private function emptyPile(int $cost, int $keep): void
    {
        $cards = $this->cards->peekTop(Zone::ExploreDeck, $cost, 100);
        $this->cards->removeFromGame(array_slice($cards, $keep));
    }
}
