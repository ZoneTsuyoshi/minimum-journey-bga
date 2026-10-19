<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Flow\PrepPhase;
use Bga\Games\MinimumJourney\Flow\PrepRuleViolation;
use Bga\Games\MinimumJourney\Flow\PrepStepResult;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use MinimumJourneyTests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 準備フェイズ（状態 20〜24。PrepPhase）をセットアップ後の実 DB で動かす。
 */
final class PrepPhaseTest extends DatabaseTestCase
{
    private const PLAYER_IDS = [2310001, 2310002, 2310003, 2310004];

    /** @var list<int> */
    private array $ids = [];
    private PrepPhase $phase;

    /**
     * @return iterable<string, array{int}>
     */
    public static function playerCounts(): iterable
    {
        yield '3 players' => [3];
        yield '4 players' => [4];
    }

    private function start(int $count): void
    {
        $this->ids = array_slice(self::PLAYER_IDS, 0, $count);
        $this->addPlayers($this->ids);
        (new GameSetup($this->cards, $this->globals, $this->rand))->run($this->ids, null);
        $this->phase = new PrepPhase($this->cards, $this->players, $this->globals, static fn(int $id): string => 'P' . $id);
        // 10 RoundStart 相当
        $this->globals->setRoundNo(1);
    }

    /**
     * 20 → 21（全員の選択）→ 22 → 24 を回す。$choices に無い選択者は 'stop'。
     * 049 の後選びは $late で指定する。
     *
     * @param array<int, string> $choices プレイヤー ID => 'draw' / 'stop'
     * @return array{transition: string, notifications: list<Notification>, lockTransition: string}
     */
    private function runStep(array $choices = [], string $late = 'stop'): array
    {
        $all = [];
        $begin = $this->phase->begin($this->ids);
        array_push($all, ...$begin->notifications);
        foreach ($this->globals->prepSelectors() as $playerId) {
            $result = $this->phase->select($playerId, PrepChoice::from($choices[$playerId] ?? 'stop'));
            array_push($all, ...$result->notifications);
        }
        $lock = $this->phase->lock($this->ids);
        array_push($all, ...$lock->notifications);
        if ($lock->transition === 'lateChoice') {
            $latePlayer = $this->globals->prepLatePlayer();
            self::assertNotNull($latePlayer);
            $this->phase->selectLate($latePlayer, PrepChoice::from($late));
        }
        $reveal = $this->phase->reveal($this->ids);
        array_push($all, ...$reveal->notifications);

        return ['transition' => (string) $reveal->transition, 'notifications' => $all, 'lockTransition' => (string) $lock->transition];
    }

    /**
     * @param list<Notification> $notifications
     * @return list<Notification>
     */
    private static function named(array $notifications, string $name): array
    {
        return array_values(array_filter($notifications, static fn(Notification $n): bool => $n->name === $name));
    }

    #[DataProvider('playerCounts')]
    public function testEveryoneStopsEndsPrepWithoutDrawing(int $count): void
    {
        $this->start($count);

        $step = $this->runStep();

        self::assertSame('allStop', $step['transition']);
        foreach ($this->ids as $id) {
            self::assertTrue($this->players->prepFinished($id));
            self::assertSame(4, $this->cards->count(Zone::Deck, $id));
            self::assertSame(0, $this->cards->count(Zone::Facedown, $id), '全員ストップの回は誰も引かない');
            self::assertSame(0, $this->cards->count(Zone::Prep, $id));
            self::assertFalse($this->players->isHalved($id));
        }
        self::assertCount($count, self::named($step['notifications'], 'choiceRevealed'));
        self::assertSame([], self::named($step['notifications'], 'faceDownSet'));
    }

    #[DataProvider('playerCounts')]
    public function testTwoDrawAndTheRestChooseAgain(int $count): void
    {
        $this->start($count);
        [$a, $b, $c] = $this->ids;
        $topA = $this->cards->peekTop(Zone::Deck, $a, 1)[0];
        $topC = $this->cards->peekTop(Zone::Deck, $c, 1)[0];

        $step = $this->runStep([$a => 'draw', $b => 'draw', $c => 'stop']);

        self::assertSame('resolve', $step['transition']);
        self::assertSame([$topA->id], $this->ids(Zone::Prep, $a));
        self::assertSame(3, $this->cards->count(Zone::Deck, $a));
        self::assertFalse($this->cards->get($topA->id)->sideways, '2 人以上のドローは横向きにならない');
        self::assertFalse($this->players->isHalved($a));
        // ストップした人は準備終了になり、山札の上を裏向きの別置き場へ引く
        self::assertTrue($this->players->prepFinished($c));
        self::assertSame([$topC->id], $this->ids(Zone::Facedown, $c));
        self::assertSame(3, $this->cards->count(Zone::Deck, $c));
        self::assertFalse($this->players->prepFinished($a));

        // 次の選択（DrawResolve は素通り）: ストップ済みの人は選ばない
        $next = $this->phase->begin($this->ids);
        self::assertSame('needChoice', $next->transition);
        $expected = [$a, $b]; // 4 人のときの d も既定でストップして準備終了している
        self::assertSame($expected, $this->globals->prepSelectors());
        self::assertNull($this->players->prepChoice($c), '前回の選択を持ち越さない');

        // 残りの全員がストップ: 誰も引かず探査へ。c の別置き場は 1 枚のまま
        foreach ($expected as $id) {
            $this->phase->select($id, PrepChoice::Stop);
        }
        $this->phase->lock($this->ids);
        $reveal = $this->phase->reveal($this->ids);
        self::assertSame('allStop', $reveal->transition);
        self::assertSame(0, $this->cards->count(Zone::Facedown, $a));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $c));
        self::assertSame(1, $this->cards->count(Zone::Prep, $a), '準備場のカードは残る');
    }

    #[DataProvider('playerCounts')]
    public function testSoloDrawTurnsCardSidewaysAndHalves(int $count): void
    {
        $this->start($count);
        [$a, $b] = $this->ids;

        $step = $this->runStep([$a => 'draw']);

        self::assertSame('soloDraw', $step['transition']);
        $cardId = $this->ids(Zone::Prep, $a)[0];
        self::assertTrue($this->cards->get($cardId)->sideways);
        self::assertTrue($this->players->isHalved($a));
        self::assertFalse($this->players->isHalved($b));
        self::assertCount(1, self::named($step['notifications'], 'cardSideways'));
        // ドローが 1 人なので、ストップした全員が裏向きで 1 枚引く
        foreach (array_slice($this->ids, 1) as $id) {
            self::assertSame(1, $this->cards->count(Zone::Facedown, $id));
            self::assertTrue($this->players->prepFinished($id));
        }
    }

    public function testSoloDrawWhenOthersAlreadyStoppedInEarlierStep(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        $this->runStep([$a => 'draw', $b => 'draw', $c => 'stop', $d => 'stop']);

        // 2 回目: a だけがドロー、b はストップ。c・d は選ばない（準備終了済み）
        $step = $this->runStep([$a => 'draw', $b => 'stop']);

        self::assertSame('soloDraw', $step['transition']);
        self::assertSame([$a, $b], $this->globals->prepSelectors());
        self::assertCount(2, $this->ids(Zone::Prep, $a));
        self::assertTrue($this->players->isHalved($a));
        // 横向きになるのはこの回に引いたカード（後から引いたほう）
        [$first, $second] = $this->cards->list(Zone::Prep, $a);
        self::assertFalse($first->sideways);
        self::assertTrue($second->sideways);
        // c は 1 回目に裏向きを 1 枚引いたまま。d も同じ。b は 2 回目に引く
        self::assertSame(1, $this->cards->count(Zone::Facedown, $c));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $b));
    }

    public function testEmptyDeckIsForcedToStopAndNothingElseIsDrawnFromIt(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $c");

        $begin = $this->phase->begin($this->ids);

        self::assertSame('needChoice', $begin->transition);
        self::assertSame([$a, $b], $this->globals->prepSelectors());
        self::assertSame(PrepChoice::Stop, $this->players->prepChoice($c));
        self::assertSame(['emptyDeck'], array_values($this->globals->prepReasons()));

        $this->phase->select($a, PrepChoice::Draw);
        $this->phase->select($b, PrepChoice::Draw);
        $this->phase->lock($this->ids);
        $reveal = $this->phase->reveal($this->ids);

        self::assertSame('resolve', $reveal->transition);
        self::assertTrue($this->players->prepFinished($c));
        self::assertSame(0, $this->cards->count(Zone::Facedown, $c), '引くカードが無い');
        $revealed = self::named($reveal->notifications, 'choiceRevealed');
        self::assertCount(3, $revealed);
        self::assertSame('emptyDeck', $revealed[2]->args['reason']);
    }

    public function testOnlyOneCanDrawBecauseOthersHaveEmptyDecksIsSoloDraw(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        foreach ([$b, $c] as $id) {
            $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $id");
        }

        $step = $this->runStep([$a => 'draw']);

        self::assertSame('soloDraw', $step['transition']);
        self::assertTrue($this->players->isHalved($a));
    }

    /**
     * 他の 3 人の山札が 0 枚（強制ストップ）でも、残り 1 人は選択者としてドロー／ストップを選べる。強制ドローにはならない。
     */
    public function testLastPlayerWithCardsStillChoosesWhenThreeOthersHaveEmptyDecks(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        foreach ([$b, $c, $d] as $id) {
            $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $id");
        }

        $begin = $this->phase->begin($this->ids);

        self::assertSame('needChoice', $begin->transition);
        self::assertSame([$a], $this->globals->prepSelectors());
        self::assertNull($this->players->prepChoice($a), 'まだ選んでいない（自動でドローにならない）');
        foreach ([$b, $c, $d] as $id) {
            self::assertSame(PrepChoice::Stop, $this->players->prepChoice($id));
        }

        // ストップを選べる: 全員ストップで何も引かない
        $this->phase->select($a, PrepChoice::Stop);
        self::assertSame('reveal', $this->phase->lock($this->ids)->transition);
        $reveal = $this->phase->reveal($this->ids);
        self::assertSame('allStop', $reveal->transition);
        self::assertSame(4, $this->cards->count(Zone::Deck, $a));
        self::assertSame(0, $this->cards->count(Zone::Prep, $a));
        self::assertFalse($this->players->isHalved($a));
    }

    public function testLastPlayerWithCardsDrawsAloneOnlyWhenChoosingDraw(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        foreach ([$b, $c, $d] as $id) {
            $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $id");
        }

        $step = $this->runStep([$a => 'draw']);

        self::assertSame('soloDraw', $step['transition']);
        self::assertTrue($this->players->isHalved($a));
        $cardId = $this->ids(Zone::Prep, $a)[0];
        self::assertTrue($this->cards->get($cardId)->sideways, '1 人だけのドローは横向き（001 §5 ③-3）');
    }

    /**
     * 他の 3 人が前の回にストップ済み（準備終了）で、その山札が 0 枚でも同じ。
     */
    public function testLastPlayerStillChoosesWhenThreeOthersStoppedEarlierWithEmptyDecks(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        $this->runStep([$a => 'draw', $b => 'draw', $c => 'stop', $d => 'stop']);
        // 2 回目に b・c・d が準備終了、b・c・d の山札を空にする
        $this->runStep([$a => 'draw', $b => 'stop']);
        foreach ([$b, $c, $d] as $id) {
            $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $id");
        }
        $this->players->setPrepFinished($a, false);

        $begin = $this->phase->begin($this->ids);

        self::assertSame('needChoice', $begin->transition);
        self::assertSame([$a], $this->globals->prepSelectors());
        self::assertNull($this->players->prepChoice($a));
    }

    public function testAllDecksEmptyGoesStraightToExplore(): void
    {
        $this->start(3);
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck'");

        $begin = $this->phase->begin($this->ids);
        self::assertSame('skipToLock', $begin->transition);
        self::assertSame('reveal', $this->phase->lock($this->ids)->transition);
        self::assertSame('allStop', $this->phase->reveal($this->ids)->transition);
    }

    public function testCannotDrawWithEmptyDeckOrWhenNotASelector(): void
    {
        $this->start(3);
        [$a, $b] = $this->ids;
        $this->phase->begin($this->ids);
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $a");

        try {
            $this->phase->select($a, PrepChoice::Draw);
            self::fail('山札 0 枚でドローを選べてはいけない');
        } catch (PrepRuleViolation $e) {
            self::assertSame(PrepRuleViolation::EMPTY_DECK, $e->reason);
        }
        $this->phase->select($a, PrepChoice::Stop);

        $this->globals->setPrepSelectors([$a]);
        try {
            $this->phase->select($b, PrepChoice::Stop);
            self::fail('選択者でない人は選べない');
        } catch (PrepRuleViolation $e) {
            self::assertSame(PrepRuleViolation::NOT_SELECTOR, $e->reason);
        }
    }

    public function testChoiceCanBeChangedUntilLockedAndMarkIsSentOnce(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $this->phase->begin($this->ids);

        $first = $this->phase->select($a, PrepChoice::Draw);
        $second = $this->phase->select($a, PrepChoice::Stop);
        $third = $this->phase->select($b, PrepChoice::Draw);
        $this->phase->select($c, PrepChoice::Draw);

        self::assertTrue($first->firstChoice);
        self::assertFalse($second->firstChoice);
        self::assertSame(PrepChoice::Stop, $this->players->prepChoice($a), '最後の選択が有効');
        self::assertCount(1, self::named($first->notifications, 'prepMarked'));
        self::assertSame([], self::named($second->notifications, 'prepMarked'), '変更では送らない');
        self::assertCount(1, self::named($third->notifications, 'prepMarked'));

        $this->phase->lock($this->ids);
        $reveal = $this->phase->reveal($this->ids);
        self::assertSame('resolve', $reveal->transition);
        self::assertSame([], $this->ids(Zone::Prep, $a));
    }

    public function testFacedownCardLeavesTheDeckAndTheNextTopIsTheFollowingCard(): void
    {
        $this->start(3);
        [$a, $b] = $this->ids;
        [$top, $next] = $this->cards->peekTop(Zone::Deck, $b, 2);

        $this->runStep([$a => 'draw']);

        self::assertSame([$top->id], $this->ids(Zone::Facedown, $b));
        self::assertNotContains($top->id, $this->ids(Zone::Deck, $b));
        self::assertSame($next->id, $this->cards->peekTop(Zone::Deck, $b, 1)[0]->id);
    }

    public function testNotificationsNeverLeakSecrets(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        $facedownTops = [
            $this->cards->peekTop(Zone::Deck, $c, 1)[0],
            $this->cards->peekTop(Zone::Deck, $d, 1)[0],
        ];
        $deckBefore = $this->ids(Zone::Deck, $c);

        $all = [];
        $begin = $this->phase->begin($this->ids);
        array_push($all, ...$begin->notifications);
        $selects = [];
        foreach ([$a => 'draw', $b => 'draw', $c => 'stop', $d => 'stop'] as $id => $choice) {
            $selects[$id] = $this->phase->select($id, PrepChoice::from($choice));
            array_push($all, ...$selects[$id]->notifications);
        }

        // 確定まで、選択の内容は本人宛ての prepChoiceRecorded 以外に出てはならない
        foreach ($all as $n) {
            if ($n->name === 'prepChoiceRecorded') {
                self::assertNotNull($n->to);
                continue;
            }
            self::assertNull($n->to);
            self::assertSame(['player_id'], array_keys($n->args), '公開の prepMarked は player_id だけ');
            self::assertSame('prepMarked', $n->name);
        }
        $private = self::named($all, 'prepChoiceRecorded');
        self::assertCount(4, $private);
        self::assertSame($a, $private[0]->to);

        $this->phase->lock($this->ids);
        $reveal = $this->phase->reveal($this->ids);

        // 裏向きに引いたカード・山札のカードの ID や券面は、どの通知にも出ない
        $json = json_encode(array_map(static fn(Notification $n): array => $n->args, $reveal->notifications), JSON_THROW_ON_ERROR);
        $drawnIds = $this->ids(Zone::Prep, $a);
        $faceDown = self::named($reveal->notifications, 'faceDownSet');
        self::assertCount(2, $faceDown);
        foreach ($faceDown as $n) {
            self::assertSame(['player_id', 'player_name'], array_keys($n->args));
        }
        foreach ($facedownTops as $card) {
            self::assertContains($card->id, $this->ids(Zone::Facedown, $card->locationArg));
            self::assertDoesNotMatchRegularExpression('/"id":' . $card->id . '[,}]/', $json);
            self::assertStringNotContainsString('"card_id":' . $card->id, $json);
        }
        // 山札に残るカードの ID も出ない
        foreach ($deckBefore as $id) {
            self::assertStringNotContainsString('"id":' . $id . ',', $json);
        }
        // 表向きに引いたカードだけが cardDrawn で ID と券面つきに公開される
        $drawn = self::named($reveal->notifications, 'cardDrawn');
        self::assertCount(2, $drawn);
        self::assertSame($drawnIds[0], $drawn[0]->args['card']['id']);
        // countersUpdate は枚数と現在の探査出力だけ
        $counters = self::named($reveal->notifications, 'countersUpdate')[0];
        self::assertSame(['deckCount', 'facedownCount', 'prepCount', 'outputNow'], array_keys($counters->args['players'][$c]));
    }

    public function testMemoryAlbumAndSafetyDeviceRecordsAreReadAndConsumed(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['draw_only'] = [$a];
        $records['stop_only'] = [$b];
        $this->globals->setNextChoiceRecords($records);

        $begin = $this->phase->begin($this->ids);

        self::assertSame('needChoice', $begin->transition);
        self::assertSame([$c, $d], $this->globals->prepSelectors());
        self::assertSame(PrepChoice::Draw, $this->players->prepChoice($a));
        self::assertSame(PrepChoice::Stop, $this->players->prepChoice($b));
        self::assertSame(GlobalValues::emptyNextChoiceRecords(), $this->globals->nextChoiceRecords(), '記録は消費される');

        $this->phase->select($c, PrepChoice::Stop);
        $this->phase->select($d, PrepChoice::Stop);
        $this->phase->lock($this->ids);
        $reveal = $this->phase->reveal($this->ids);
        self::assertSame('soloDraw', $reveal->transition, '強制ドローの 1 人だけがドロー');
        self::assertTrue($this->players->isHalved($a));
    }

    public function testTimeStopperChoosesAfterOthersAreRevealed(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['late'] = $c;
        $this->globals->setNextChoiceRecords($records);

        $begin = $this->phase->begin($this->ids);
        self::assertSame([$a, $b], $this->globals->prepSelectors());
        self::assertSame($c, $this->globals->prepLatePlayer());
        self::assertSame('needChoice', $begin->transition);

        $this->phase->select($a, PrepChoice::Draw);
        $this->phase->select($b, PrepChoice::Stop);
        $lock = $this->phase->lock($this->ids);

        self::assertSame('lateChoice', $lock->transition);
        $revealed = self::named($lock->notifications, 'choiceRevealed');
        self::assertSame([$a, $b], array_map(static fn(Notification $n): mixed => $n->args['player_id'], $revealed));
        self::assertSame([], $this->ids(Zone::Prep, $a), '開示の前にはカードを引かない');

        try {
            $this->phase->selectLate($a, PrepChoice::Draw);
            self::fail('後選びの人以外は選べない');
        } catch (PrepRuleViolation $e) {
            self::assertSame(PrepRuleViolation::NOT_LATE_PLAYER, $e->reason);
        }
        self::assertSame('chosen', $this->phase->selectLate($c, PrepChoice::Draw)->transition);

        $reveal = $this->phase->reveal($this->ids);
        self::assertSame('resolve', $reveal->transition);
        $second = self::named($reveal->notifications, 'choiceRevealed');
        self::assertCount(1, $second, '後選びの人の分だけ送る');
        self::assertSame($c, $second[0]->args['player_id']);
        self::assertNull($this->globals->prepLatePlayer());
    }

    public function testTimeStopperStopMakesTheOtherDrawerSolo(): void
    {
        $this->start(3);
        [$a, , $c] = $this->ids;
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['late'] = $c;
        $this->globals->setNextChoiceRecords($records);

        $step = $this->runStep([$a => 'draw'], 'stop');

        self::assertSame('lateChoice', $step['lockTransition']);
        self::assertSame('soloDraw', $step['transition']);
        self::assertSame(1, $this->cards->count(Zone::Facedown, $c));
    }

    public function testBigBangCancelsEveryonesPrepFinishedAndForcesTheDraw(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        // 1 回目: a・b がドロー、c・d はストップして準備終了
        $this->runStep([$a => 'draw', $b => 'draw']);
        self::assertTrue($this->players->prepFinished($c));
        // 054 の記録（I-09 が書く）。d は山札 0 枚
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $d");
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['force_draw'] = true;
        $records['late'] = $a;
        $this->globals->setNextChoiceRecords($records);

        $begin = $this->phase->begin($this->ids);

        self::assertSame('skipToLock', $begin->transition);
        self::assertSame([], $this->globals->prepSelectors());
        self::assertNull($this->globals->prepLatePlayer(), '054 があれば 049 の後選びは無視');
        $cleared = self::named($begin->notifications, 'prepFinishedCleared');
        self::assertCount(1, $cleared);
        self::assertSame([$c], $cleared[0]->args['player_ids'], '山札 0 枚の d は含めない');
        self::assertFalse($this->players->prepFinished($c));
        self::assertTrue($this->players->prepFinished($d));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $c), '裏向きカードは別置き場に残る');
        foreach ([$a, $b, $c] as $id) {
            self::assertSame(PrepChoice::Draw, $this->players->prepChoice($id));
        }

        self::assertSame('reveal', $this->phase->lock($this->ids)->transition);
        $reveal = $this->phase->reveal($this->ids);
        self::assertSame('resolve', $reveal->transition);
        self::assertCount(1, $this->ids(Zone::Prep, $c));
        self::assertCount(2, $this->ids(Zone::Prep, $a));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $c), '再びストップしていないので増えない');
    }

    public function testForcedStopForEmptyDeckIsLoggedOnlyAtTheFirstStep(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $c");

        $first = $this->runStep([$a => 'draw', $b => 'draw']);
        $forced = self::named($first['notifications'], 'prepForced');
        self::assertCount(1, $forced, '通常のプレイヤーには出ない');
        self::assertNull($forced[0]->to);
        self::assertSame($c, $forced[0]->args['player_id']);
        self::assertSame('stop', $forced[0]->args['choice']);
        self::assertSame('emptyDeck', $forced[0]->args['reason']);
        self::assertStringContainsString('must Stop', $forced[0]->message);
        self::assertTrue($this->players->prepFinished($c));

        $second = $this->runStep([$a => 'draw', $b => 'draw']);
        self::assertSame([], self::named($second['notifications'], 'prepForced'), '準備終了後は繰り返さない');
    }

    public function testNoForcedLogForNormalPlayers(): void
    {
        $this->start(3);
        $step = $this->runStep([$this->ids[0] => 'draw']);
        self::assertSame([], self::named($step['notifications'], 'prepForced'));
    }

    public function testBigBangForcedDrawIsLoggedAtTheStepItApplies(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;
        $this->runStep([$a => 'draw', $b => 'draw']);
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $d");
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['force_draw'] = true;
        $this->globals->setNextChoiceRecords($records);

        $begin = $this->phase->begin($this->ids);
        $forced = self::named($begin->notifications, 'prepForced');
        self::assertCount(3, $forced, 'd は準備終了済みで山札 0 枚なので対象外');
        self::assertSame([$a, $b, $c], array_map(static fn(Notification $n): int => $n->args['player_id'], $forced));
        foreach ($forced as $n) {
            self::assertSame('draw', $n->args['choice']);
            self::assertSame('bigBang', $n->args['reason']);
            self::assertSame('Seed of the Big Bang', $n->args['card_name']);
            self::assertSame('${player_name} must Draw (${card_name})', $n->message);
        }

        // 054 は 1 回の選択だけ。次の回には出ない
        $this->phase->lock($this->ids);
        $this->phase->reveal($this->ids);
        $next = $this->phase->begin($this->ids);
        self::assertSame([], self::named($next->notifications, 'prepForced'));
    }

    public function testBigBangLogsForcedStopForUnfinishedPlayerWithEmptyDeck(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $c");
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['force_draw'] = true;
        $this->globals->setNextChoiceRecords($records);

        $forced = self::named($this->phase->begin($this->ids)->notifications, 'prepForced');
        self::assertCount(3, $forced);
        self::assertSame('emptyDeck', $forced[2]->args['reason']);
        self::assertArrayNotHasKey('card_name', $forced[2]->args);
    }

    public function testBigBangWithOnlyOnePlayerWhoCanDrawIsSoloDraw(): void
    {
        $this->start(3);
        [$a, $b, $c] = $this->ids;
        foreach ([$b, $c] as $id) {
            $this->db->execute("UPDATE `card` SET `card_location` = 'acquired' WHERE `card_location` = 'deck' AND `card_location_arg` = $id");
        }
        $records = GlobalValues::emptyNextChoiceRecords();
        $records['force_draw'] = true;
        $this->globals->setNextChoiceRecords($records);

        $this->phase->begin($this->ids);
        $this->phase->lock($this->ids);
        $reveal = $this->phase->reveal($this->ids);

        self::assertSame('soloDraw', $reveal->transition);
        self::assertTrue($this->players->isHalved($a));
    }

    public function testRunningTheWholePhaseToTheEnd(): void
    {
        $this->start(4);
        [$a, $b, $c, $d] = $this->ids;

        // 4 人ドロー → 3 人ドロー（d は停止） → 2 人 → 1 人だけ
        self::assertSame('resolve', $this->runStep([$a => 'draw', $b => 'draw', $c => 'draw', $d => 'draw'])['transition']);
        self::assertSame('resolve', $this->runStep([$a => 'draw', $b => 'draw', $c => 'draw', $d => 'stop'])['transition']);
        self::assertSame('resolve', $this->runStep([$a => 'draw', $b => 'draw', $c => 'stop'])['transition']);
        self::assertSame('soloDraw', $this->runStep([$a => 'draw', $b => 'stop'])['transition']);

        self::assertSame(4, $this->cards->count(Zone::Prep, $a));
        self::assertSame(0, $this->cards->count(Zone::Deck, $a));
        self::assertSame(3, $this->cards->count(Zone::Prep, $b));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $b));
        self::assertSame(2, $this->cards->count(Zone::Prep, $c));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $c));
        self::assertSame(1, $this->cards->count(Zone::Prep, $d));
        self::assertSame(1, $this->cards->count(Zone::Facedown, $d));
        self::assertTrue($this->players->isHalved($a));
    }

    public function testPrepCardsKeepDrawOrder(): void
    {
        $this->start(3);
        [$a] = $this->ids;
        $tops = array_map(static fn($c): int => $c->id, $this->cards->peekTop(Zone::Deck, $a, 2));
        $this->runStep([$a => 'draw', $this->ids[1] => 'draw']);
        $this->runStep([$a => 'draw', $this->ids[1] => 'draw']);

        self::assertSame($tops, $this->ids(Zone::Prep, $a), '引いた順に準備場へ並ぶ');
    }
}
