<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Integration;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Data\ResolveEntryRepository;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\DrawResolver;
use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Flow\ExplorePhase;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Flow\PrepPhase;
use MinimumJourneyTests\Support\DatabaseTestCase;

/**
 * ドロー時効果の解決エンジン（状態 30 と割り込み 31〜35。DrawResolver）を、実 DB で 010 §7 の検証例どおりに動かす。
 * 例 7・9・12 は同名カードの重なりで、各カードが 1 枚しかないため起こりえない（010 D8）。
 */
final class DrawResolverTest extends DatabaseTestCase
{
    private const A = 2310001;
    private const B = 2310002;
    private const C = 2310003;
    private const D = 2310004;

    // カード番号
    private const ROBOT_ARM = 5;
    private const LASER = 7;
    private const SATELLITE = 8;
    private const DRILL = 9;
    private const MANUAL_010 = 10;
    private const RECYCLER = 14;
    private const SOLAR_MANUAL = 20;
    private const FUEL = 24;
    private const ALBUM = 28;
    private const SAFETY = 29;
    private const MILKY_MANUAL = 35;
    private const WORMHOLE = 36;
    private const JAMMER = 39;
    private const POD = 43;
    private const PART = 46;
    private const TIME_STOPPER = 49;
    private const SPACE_MANUAL = 50;
    private const SCALES = 52;
    private const SUPERFLARE = 53;
    private const BIG_BANG = 54;

    /** コスト 2〜8 のカード（入れ替え・列用）。コスト 2: 004、4: 019・022、6: 037、8: 047 */
    private const COST2 = 4;
    private const COST4 = 19;
    private const COST4B = 22;

    /** @var list<int> */
    private array $ids = [];
    private DrawResolver $resolver;
    private int $amulet = self::A;

    private function table(int $count, int $amulet = self::A): void
    {
        $this->ids = array_slice([self::A, self::B, self::C, self::D], 0, $count);
        $this->addPlayers($this->ids);
        $this->globals->setRoundNo(2);
        $this->amulet = $amulet;
        $this->cards->create(57, 0, Zone::Charm, $amulet);
        $this->resolver = new DrawResolver(
            $this->cards,
            $this->players,
            $this->globals,
            new ResolveEntryRepository($this->db),
            new ExploreOutputCalculator($this->cards, $this->globals),
            static fn(int $id): string => 'P' . $id,
            fn(): array => $this->ids,
        );
    }

    private function mk(int $no, Zone $zone, int $arg, int $order = 0): Card
    {
        return $this->cards->create($no, $no <= 3 ? 1 : 0, $zone, $arg, $order);
    }

    /**
     * 山札を作る（先頭が一番上）。足りない分は国旗（001）で埋める。
     *
     * @param list<int> $top
     * @return list<Card>
     */
    private function deck(int $playerId, array $top = [], int $total = 6): array
    {
        $nos = array_pad($top, max($total, count($top)), 1);
        $cards = [];
        foreach ($nos as $i => $no) {
            $cards[] = $this->mk($no, Zone::Deck, $playerId, count($nos) - $i);
        }
        return $cards;
    }

    private function drawn(int $playerId, int $no, int $order = 5): Card
    {
        return $this->mk($no, Zone::Prep, $playerId, $order);
    }

    private function row(int $no, int $cost, int $slot = 0): Card
    {
        return $this->mk($no, Zone::Row, $cost, $slot);
    }

    /**
     * 解決を最後まで進める。割り込みには $answers で答える（無ければ無難な既定）。
     *
     * @param array<int, Card> $drawn
     * @param array<string, mixed> $answers pod: [playerId => bool]、copy: カード ID、wormhole: ?列のカード ID、
     *     laser: カード ID、simul: [playerId => [action, payload]]
     * @return array{transitions: list<string|null>, notes: list<Notification>}
     */
    private function resolve(array $drawn, array $answers = []): array
    {
        $this->resolver->enqueue($drawn);
        $transitions = [];
        $notes = [];
        for ($i = 0; $i < 30; $i++) {
            $step = $this->resolver->advance();
            array_push($notes, ...$step->notifications);
            $transitions[] = $step->transition;
            switch ($step->transition) {
                case 'done':
                    return ['transitions' => $transitions, 'notes' => $notes];
                case 'pod':
                    foreach ($this->resolver->podTargetPlayers() as $playerId) {
                        $result = $this->resolver->decideReturn($playerId, $answers['pod'][$playerId] ?? false);
                        array_push($notes, ...$result->notifications);
                    }
                    break;
                case 'copy':
                    $owner = $this->resolver->copyOwner();
                    self::assertNotNull($owner);
                    array_push($notes, ...$this->resolver->chooseCopy($owner, $answers['copy'])->notifications);
                    break;
                case 'wormhole':
                    $owner = $this->resolver->wormholeOwner();
                    self::assertNotNull($owner);
                    array_push($notes, ...$this->resolver->wormhole($owner, $answers['wormhole'] ?? null)->notifications);
                    break;
                case 'laser':
                    $owner = $this->resolver->laserOwner();
                    self::assertNotNull($owner);
                    array_push($notes, ...$this->resolver->chooseLockedCard($owner, $answers['laser'])->notifications);
                    break;
                case 'simul':
                    foreach (array_keys($this->resolver->simulRequests()) as $playerId) {
                        $answer = $answers['simul'][$playerId] ?? null;
                        $result = $answer === null
                            ? $this->resolver->simulZombie($playerId)
                            : $this->resolver->simulAction($playerId, $answer[0], $answer[1]);
                        array_push($notes, ...$result->notifications);
                    }
                    break;
                default:
                    self::fail('unexpected transition: ' . var_export($step->transition, true));
            }
        }
        self::fail('resolver did not finish');
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
     * @param list<Notification> $notes
     * @return list<string>
     */
    private static function names(array $notes): array
    {
        return array_map(static fn(Notification $n): string => $n->name, $notes);
    }

    /**
     * @param list<Notification> $notes
     * @return list<int>
     */
    private static function suppressedPlayers(array $notes): array
    {
        return array_map(
            static fn(Notification $n): int => (int) $n->args['player_id'],
            self::named($notes, 'effectSuppressed'),
        );
    }

    private function prepCount(int $playerId): int
    {
        return $this->cards->count(Zone::Prep, $playerId);
    }

    /**
     * @return array{force_draw: bool, draw_only: list<int>, stop_only: list<int>, late: int|null}
     */
    private function records(): array
    {
        return $this->globals->nextChoiceRecords();
    }

    // ---- 010 §7 の検証例 ----

    /** 例 1: 039 + 043（F2・D6・D7） */
    public function testExample1JammerAndPod(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $a = $this->drawn(self::A, self::JAMMER);
        $b = $this->drawn(self::B, self::POD);
        $c = $this->drawn(self::C, self::ROBOT_ARM);
        $deckA = $this->cards->count(Zone::Deck, self::A);

        $result = $this->resolve(
            [self::A => $a, self::B => $b, self::C => $c],
            ['pod' => [self::A => true, self::C => true]],
        );

        // 005（太陽系）は 039 に止められ、043 が有効（乙女座銀河団）。A と C が同時に決める
        self::assertSame(['pod', 'done'], $result['transitions']);
        self::assertSame([self::C], self::suppressedPlayers($result['notes']));
        $suppressed = self::named($result['notes'], 'effectSuppressed')[0];
        self::assertSame('Jamming Transmitter', $suppressed->args['source_name']);
        // 決定の通知に内容は載らない（H8）
        foreach (self::named($result['notes'], 'podDecided') as $n) {
            self::assertSame(['player_id', 'player_name'], array_keys($n->args));
        }
        // A が 039 を戻しても、039 の抑止作用は残り、C の 005 は止められたまま（D6）。戻した後に準備したカードの効果は発動しない（D7）
        self::assertCount(2, self::named($result['notes'], 'cardReturned'));
        self::assertCount(2, self::named($result['notes'], 'cardDrawn'));
        self::assertSame(1, $this->prepCount(self::A));
        self::assertSame($deckA, $this->cards->count(Zone::Deck, self::A));
        self::assertNotContains('deckLooked', self::names($result['notes']));
        self::assertSame(1, $this->prepCount(self::B));
    }

    /** 例 2: 039 + 053 + 043（F3・F4・D8）。互いに無効になり、誰もカードを戻せない */
    public function testExample2JammerSuperflareAndPod(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::JAMMER),
            self::B => $this->drawn(self::B, self::SUPERFLARE),
            self::C => $this->drawn(self::C, self::POD),
        ]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame([self::A, self::B, self::C], self::suppressedPlayers($result['notes']));
        self::assertNotContains('cardReturned', self::names($result['notes']));
    }

    /** 例 3: 053 + 043 */
    public function testExample3SuperflareStopsPod(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::SUPERFLARE),
            self::B => $this->drawn(self::B, self::POD),
            self::C => $this->drawn(self::C, self::ROBOT_ARM),
        ]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame([self::B, self::C], self::suppressedPlayers($result['notes']));
    }

    /** 例 4: 039 + 乙女座銀河団の他カード */
    public function testExample4JammerAllowsVirgoCards(): void
    {
        $this->table(4);
        $this->deck(self::A);
        $b = $this->deck(self::B, [19, 1, 1, 1, 1, 1]);
        $this->deck(self::C, [self::COST2, 1, 1, 1, 1, 1]);
        $this->deck(self::D);
        $this->row(6, 2, 0);
        $deckD = $this->ids(Zone::Deck, self::D);

        $result = $this->resolve(
            [
                self::A => $this->drawn(self::A, self::JAMMER),
                self::B => $this->drawn(self::B, self::MILKY_MANUAL),
                self::C => $this->drawn(self::C, self::WORMHOLE),
                self::D => $this->drawn(self::D, self::ROBOT_ARM),
            ],
            ['wormhole' => null, 'simul' => [self::B => ['actPickFromDeck', $b[0]->id]]],
        );

        // 036（P4）→ 035（P5-同時）。005 は止められて発動しない
        self::assertSame(['wormhole', 'simul', 'done'], $result['transitions']);
        self::assertSame([self::D], self::suppressedPlayers($result['notes']));
        self::assertSame($deckD, $this->ids(Zone::Deck, self::D));
        self::assertSame($b[0]->id, $this->cards->peekTop(Zone::Deck, self::B, 1)[0]->id);
    }

    /** 例 4（039 に 008 を重ねた場合）: 008 は太陽系なので止められ、コピー元を選ばない */
    public function testExample4SatelliteIsStoppedByJammer(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $this->row(self::ROBOT_ARM, 2);

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::JAMMER),
            self::B => $this->drawn(self::B, self::SATELLITE),
        ]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame([self::B], self::suppressedPlayers($result['notes']));
    }

    /** 例 5: 024 と準備終了済みのプレイヤー */
    public function testExample5FuelPreparesForFinishedPlayers(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $this->players->setPrepFinished(self::C, true);
        $this->players->setPrepFinished(self::D, true);

        $result = $this->resolve(
            [self::A => $this->drawn(self::A, self::FUEL), self::B => $this->drawn(self::B, self::ROBOT_ARM)],
        );

        self::assertSame(['simul', 'done'], $result['transitions']);
        self::assertSame(1, $this->prepCount(self::C));
        self::assertSame(1, $this->prepCount(self::D));
        self::assertSame(1, $this->prepCount(self::A));
        $fuel = array_values(array_filter(
            self::named($result['notes'], 'cardDrawn'),
            static fn(Notification $n): bool => $n->args['reason'] === 'fuel',
        ));
        self::assertCount(2, $fuel);
        self::assertSame('Overflowing Fuel', $fuel[0]->args['source_name']);
    }

    /** 例 5 派生 a: 053 に止められた 024 は誰にも作用しない */
    public function testExample5aFuelStoppedBySuperflare(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $this->players->setPrepFinished(self::C, true);
        $this->players->setPrepFinished(self::D, true);

        $result = $this->resolve([self::A => $this->drawn(self::A, self::FUEL), self::B => $this->drawn(self::B, self::SUPERFLARE)]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame(0, $this->prepCount(self::C));
        self::assertSame(0, $this->prepCount(self::D));
    }

    /** 例 5 派生 b: 043 で 024 を戻せば、C・D は準備しない。戻さなければ準備する */
    public function testExample5bFuelReturnedByPod(): void
    {
        foreach ([true, false] as $returned) {
            $this->setUp();
            $this->table(4);
            foreach ($this->ids as $id) {
                $this->deck($id);
            }
            $this->players->setPrepFinished(self::C, true);
            $this->players->setPrepFinished(self::D, true);

            $result = $this->resolve(
                [self::A => $this->drawn(self::A, self::FUEL), self::B => $this->drawn(self::B, self::POD)],
                ['pod' => [self::A => $returned]],
            );

            self::assertSame(['pod', 'done'], $result['transitions']);
            self::assertSame($returned ? 0 : 1, $this->prepCount(self::C), 'returned=' . var_export($returned, true));
            self::assertSame($returned ? 0 : 1, $this->prepCount(self::D));
        }
    }

    /** 例 5 派生 c（N38）: 054 の強制ドローで準備終了が打ち消された回は、024 は誰にも作用しない */
    public function testExample5cFuelWithoutFinishedPlayers(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        // 準備終了が打ち消された後（20 で打ち消し済み）: C・D は準備終了していない
        $result = $this->resolve([self::A => $this->drawn(self::A, self::FUEL), self::B => $this->drawn(self::B, self::ROBOT_ARM)]);

        self::assertSame(['simul', 'done'], $result['transitions']);
        self::assertSame(0, $this->prepCount(self::C));
        self::assertSame(0, $this->prepCount(self::D));
    }

    /** 例 6: 次の準備選択を縛るカード同士（028・029・054）と、20 での読み取り */
    public function testExample6NextChoiceRecordsAndPrepStep(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::ALBUM),
            self::B => $this->drawn(self::B, self::SAFETY),
            self::C => $this->drawn(self::C, self::BIG_BANG),
        ]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame(
            ['force_draw' => true, 'draw_only' => [self::A], 'stop_only' => [self::B], 'late' => null],
            $this->records(),
        );
        self::assertSame(['forceDraw', 'drawOnly', 'stopOnly'], array_map(
            static fn(Notification $n): string => (string) $n->args['kind'],
            self::named($result['notes'], 'nextChoiceRecorded'),
        ));

        // 20 は 054 を優先し、029 の「ストップしか選べない」は無視する（D2）。全員ドロー確定で選択者はいない
        $prep = new PrepPhase($this->cards, $this->players, $this->globals, static fn(int $id): string => 'P' . $id);
        $prep->begin($this->ids);
        foreach ($this->ids as $id) {
            self::assertSame(PrepChoice::Draw, $this->players->prepChoice($id));
        }
        self::assertSame([], $this->globals->prepSelectors());
    }

    /** 例 6 派生: 054 でなく 049 の場合。A はドロー、B はストップ、C は後選び */
    public function testExample6VariantWithTimeStopper(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $this->resolve([
            self::A => $this->drawn(self::A, self::ALBUM),
            self::B => $this->drawn(self::B, self::SAFETY),
            self::C => $this->drawn(self::C, self::TIME_STOPPER),
        ]);

        self::assertSame(
            ['force_draw' => false, 'draw_only' => [self::A], 'stop_only' => [self::B], 'late' => self::C],
            $this->records(),
        );
        $prep = new PrepPhase($this->cards, $this->players, $this->globals, static fn(int $id): string => 'P' . $id);
        $prep->begin($this->ids);
        self::assertSame(PrepChoice::Draw, $this->players->prepChoice(self::A));
        self::assertSame(PrepChoice::Stop, $this->players->prepChoice(self::B));
        self::assertSame(self::C, $this->globals->prepLatePlayer());
        self::assertSame([], $this->globals->prepSelectors());
    }

    /** 例 6 派生 2（H3）: 054 があれば 049 の持ち主もドロー固定。準備終了していた人の準備終了は打ち消される */
    public function testExample6Variant2BigBangFixesTimeStopper(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $this->players->setPrepFinished(self::A, true);
        $this->resolve([
            self::B => $this->drawn(self::B, self::SAFETY),
            self::C => $this->drawn(self::C, self::BIG_BANG),
            self::D => $this->drawn(self::D, self::TIME_STOPPER),
        ]);

        $prep = new PrepPhase($this->cards, $this->players, $this->globals, static fn(int $id): string => 'P' . $id);
        $result = $prep->begin($this->ids);
        self::assertNull($this->globals->prepLatePlayer());
        self::assertSame(PrepChoice::Draw, $this->players->prepChoice(self::D));
        self::assertSame(PrepChoice::Draw, $this->players->prepChoice(self::A));
        self::assertFalse($this->players->prepFinished(self::A));
        self::assertSame('prepFinishedCleared', $result->notifications[0]->name);
    }

    /** 例 8: 036 + 007。036 が先で、007 は入れ替え後の列から選ぶ（D3・D4） */
    public function testExample8WormholeBeforeLaser(): void
    {
        $this->table(4);
        $a = $this->deck(self::A, [self::COST4, 1, 1, 1, 1, 1]);
        foreach ([self::B, self::C, self::D] as $id) {
            $this->deck($id);
        }
        $x = $this->row(self::COST4B, 4);
        $this->row(self::COST2, 2);

        $result = $this->resolve(
            [
                self::A => $this->drawn(self::A, self::WORMHOLE),
                self::B => $this->drawn(self::B, self::LASER),
                self::C => $this->drawn(self::C, self::ALBUM),
                self::D => $this->drawn(self::D, self::ROBOT_ARM),
            ],
            ['wormhole' => $x->id, 'laser' => $a[0]->id],
        );

        self::assertSame(['wormhole', 'laser', 'simul', 'done'], $result['transitions']);
        // 007 の候補は入れ替え後の列（A の山札にあったカードが入り、X は列から消える）。その 1 枚を選べた
        self::assertSame(['player_id' => self::B, 'card_id' => $a[0]->id], $this->globals->lockedCard());
        self::assertSame($a[0]->id, $this->cards->get($a[0]->id)->id);
        self::assertSame(Zone::Row, $this->cards->get($a[0]->id)->zone);
        self::assertSame(Zone::Deck, $this->cards->findByCardNo(self::COST4B)[0]->zone);
        self::assertSame([self::C], $this->records()['draw_only']);
    }

    /** 例 10: 054 + 039（054 は抑止されない） */
    public function testExample10BigBangIsNotSuppressed(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::BIG_BANG),
            self::B => $this->drawn(self::B, self::JAMMER),
            self::C => $this->drawn(self::C, self::ALBUM),
        ]);

        self::assertSame([self::C], self::suppressedPlayers($result['notes']));
        self::assertSame(
            ['force_draw' => true, 'draw_only' => [], 'stop_only' => [], 'late' => null],
            $this->records(),
        );
    }

    /** 例 11: 008 のコピー（024 を P3 で解決） */
    public function testExample11SatelliteCopiesFuel(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id, [self::ROBOT_ARM]);
        }
        $fuel = $this->row(self::FUEL, 4);
        $this->players->setPrepFinished(self::C, true);

        $result = $this->resolve(
            [self::A => $this->drawn(self::A, self::SATELLITE), self::B => $this->drawn(self::B, self::SOLAR_MANUAL)],
            ['copy' => $fuel->id],
        );

        self::assertSame(['copy', 'simul', 'done'], $result['transitions']);
        self::assertSame(1, $this->prepCount(self::C));
        self::assertSame('Overflowing Fuel', self::named($result['notes'], 'copySourceChosen')[0]->args['source_name']);
        // 008 の解決レベルはコピー元のレベル（P3）なので、020 の選択（P5）より前に C が準備している
        $names = self::names($result['notes']);
        self::assertLessThan(array_search('deckShuffled', $names, true) ?: PHP_INT_MAX, array_search('cardDrawn', $names, true));
    }

    /** 例 11 派生（K1）: 036 がいても、コピー元は 036 の入れ替え前の列から選び、P4 の前に解決する */
    public function testExample11VariantCopyIsChosenBeforeWormhole(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $d = $this->deck(self::D, [self::COST4, 1, 1, 1, 1, 1]);
        $fuel = $this->row(self::FUEL, 4);
        $this->players->setPrepFinished(self::C, true);

        $result = $this->resolve(
            [
                self::A => $this->drawn(self::A, self::SATELLITE),
                self::D => $this->drawn(self::D, self::WORMHOLE),
            ],
            ['copy' => $fuel->id, 'wormhole' => $fuel->id],
        );

        self::assertSame(['copy', 'wormhole', 'done'], $result['transitions']);
        $names = self::names($result['notes']);
        self::assertLessThan(array_search('wormholeSwapped', $names, true), array_search('cardDrawn', $names, true));
        self::assertSame(1, $this->prepCount(self::C));
        self::assertSame(Zone::Row, $this->cards->get($d[0]->id)->zone);
    }

    /** 例 11 派生 2: 046 の付加カードが 008 なら、同じ時点でコピー元を選べる */
    public function testExample11Variant2AttachedSatellite(): void
    {
        $this->table(3);
        $this->deck(self::A, [self::SATELLITE, 1, 1, 1, 1, 1]);
        $this->deck(self::B);
        $this->deck(self::C);
        $fuel = $this->row(self::FUEL, 4);
        $this->players->setPrepFinished(self::C, true);

        $result = $this->resolve(
            [self::A => $this->drawn(self::A, self::PART), self::B => $this->drawn(self::B, self::ROBOT_ARM)],
            ['copy' => $fuel->id],
        );

        self::assertSame(['copy', 'simul', 'done'], $result['transitions']);
        self::assertSame(1, $this->prepCount(self::C));
        self::assertCount(1, self::named($result['notes'], 'attachedCardDrawn'));
    }

    /** 例 13: 054 + 053 + 043（053 は 054 を止めない） */
    public function testExample13BigBangSurvivesSuperflare(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::BIG_BANG),
            self::B => $this->drawn(self::B, self::SUPERFLARE),
            self::C => $this->drawn(self::C, self::POD),
            self::D => $this->drawn(self::D, self::ROBOT_ARM),
        ]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame([self::C, self::D], self::suppressedPlayers($result['notes']));
        self::assertTrue($this->records()['force_draw']);
    }

    /** 例 13 派生 a: 043 が有効で、A が 054 を戻せば強制ドローは残らない。戻さなければ残る */
    public function testExample13aBigBangReturnedByPod(): void
    {
        foreach ([true, false] as $returned) {
            $this->setUp();
            $this->table(4);
            foreach ($this->ids as $id) {
                $this->deck($id);
            }

            $result = $this->resolve(
                [
                    self::A => $this->drawn(self::A, self::BIG_BANG),
                    self::B => $this->drawn(self::B, self::ALBUM),
                    self::C => $this->drawn(self::C, self::POD),
                    self::D => $this->drawn(self::D, self::ROBOT_ARM),
                ],
                ['pod' => [self::A => $returned]],
            );

            self::assertSame(['pod', 'simul', 'done'], $result['transitions']);
            self::assertSame(!$returned, $this->records()['force_draw']);
            self::assertSame([self::B], $this->records()['draw_only']);
            self::assertEmpty(array_filter(
                self::named($result['notes'], 'nextChoiceRecorded'),
                static fn(Notification $n): bool => $n->args['kind'] === 'forceDraw' && $returned,
            ));
        }
    }

    /** 例 13 派生 b: 039 を戻しても抑止は残る。054 を戻せば強制ドローは残らない */
    public function testExample13bJammerReturnedKeepsEffect(): void
    {
        $this->table(4);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }

        $result = $this->resolve(
            [
                self::A => $this->drawn(self::A, self::BIG_BANG),
                self::B => $this->drawn(self::B, self::JAMMER),
                self::C => $this->drawn(self::C, self::POD),
                self::D => $this->drawn(self::D, self::ROBOT_ARM),
            ],
            ['pod' => [self::A => true, self::B => true]],
        );

        self::assertSame(['pod', 'done'], $result['transitions']);
        self::assertSame([self::D], self::suppressedPlayers($result['notes']));
        self::assertFalse($this->records()['force_draw']);
    }

    /** 例 14: 046 の付加カードが 039。まだ解決していない他プレイヤーの効果を止める（H6・J2・J4） */
    public function testExample14AttachedJammer(): void
    {
        $this->table(4);
        $this->deck(self::A, [self::JAMMER, 1, 1, 1, 1, 1]);
        foreach ([self::B, self::D] as $id) {
            $this->deck($id);
        }
        $this->deck(self::C, [self::COST2, 1, 1, 1, 1, 1]);

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::PART),
            self::B => $this->drawn(self::B, self::ROBOT_ARM),
            self::C => $this->drawn(self::C, self::WORMHOLE),
            self::D => $this->drawn(self::D, self::ALBUM),
        ]);

        self::assertSame(['done'], $result['transitions']);
        self::assertSame([self::B, self::D], self::suppressedPlayers($result['notes']));
        // 036（乙女座銀河団）は有効で P4 で解決された（列が空なので表にして山札へ戻しシャッフル）
        self::assertCount(1, self::named($result['notes'], 'wormholeRevealed'));
        self::assertSame([], $this->records()['draw_only']);
        self::assertSame(1, $this->cards->count(Zone::Attached, $this->cards->findByCardNo(self::PART)[0]->id));
    }

    /** 例 14 派生（J1）: 付加カードが 024 なら本来のレベル（P3）で解決する */
    public function testExample14VariantAttachedFuel(): void
    {
        $this->table(3);
        $this->deck(self::A, [self::FUEL, 1, 1, 1, 1, 1]);
        $this->deck(self::B);
        $this->deck(self::C);
        $this->players->setPrepFinished(self::C, true);

        $result = $this->resolve([self::A => $this->drawn(self::A, self::PART), self::B => $this->drawn(self::B, self::ROBOT_ARM)]);

        self::assertSame(['simul', 'done'], $result['transitions']);
        self::assertSame(1, $this->prepCount(self::C));
    }

    // ---- 008 のカード別テストケース ----

    /** TC-053-3: 付加カードの 053 は、まだ解決していない他プレイヤーの効果だけを止める */
    public function testAttachedSuperflareStopsUnresolvedEffects(): void
    {
        $this->table(3);
        $this->deck(self::A, [self::SUPERFLARE, 1, 1, 1, 1, 1]);
        $this->deck(self::B);
        $this->deck(self::C);

        $result = $this->resolve([
            self::A => $this->drawn(self::A, self::PART),
            self::B => $this->drawn(self::B, self::ROBOT_ARM),
            self::C => $this->drawn(self::C, self::ALBUM),
        ]);

        self::assertSame([self::B, self::C], self::suppressedPlayers($result['notes']));
        self::assertSame(['done'], $result['transitions']);
    }

    /** TC-046-4: 山札 0 枚なら付加カードはない */
    public function testPartWithEmptyDeckAttachesNothing(): void
    {
        $this->table(2);
        $this->deck(self::B);
        $result = $this->resolve([self::A => $this->drawn(self::A, self::PART), self::B => $this->drawn(self::B, self::ROBOT_ARM)]);

        self::assertNotContains('attachedCardDrawn', self::names($result['notes']));
    }

    /** 046 が付加カードとして 043 を引くと、他プレイヤーが戻すかを決める（P2 相当。H6） */
    public function testAttachedPodLetsOthersReturn(): void
    {
        $this->table(3);
        $this->deck(self::A, [self::POD, 1, 1, 1, 1, 1]);
        $this->deck(self::B);
        $this->deck(self::C);

        $result = $this->resolve(
            [self::A => $this->drawn(self::A, self::PART), self::B => $this->drawn(self::B, self::ALBUM)],
            ['pod' => [self::B => true]],
        );

        self::assertSame(['pod', 'done'], $result['transitions']);
        self::assertSame([], $this->records()['draw_only']);
        self::assertCount(1, self::named($result['notes'], 'cardReturned'));
    }

    /** TC-005-1・TC-052-1・TC-010-1・TC-020-1・TC-050-1・TC-035-1: 山札の操作（35 の各アクション） */
    public function testSimultaneousDeckEffects(): void
    {
        $this->table(4);
        $a = $this->deck(self::A, [2, 3, 1, 1, 1, 1]);
        $b = $this->deck(self::B, [2, 3, 1, 1, 1, 1]);
        $c = $this->deck(self::C, [2, 3, 1, 1, 1, 1]);
        $d = $this->deck(self::D, [2, 3, 1, 1, 1, 1]);

        $result = $this->resolve(
            [
                self::A => $this->drawn(self::A, self::ROBOT_ARM),
                self::B => $this->drawn(self::B, self::SCALES),
                self::C => $this->drawn(self::C, self::MANUAL_010),
                self::D => $this->drawn(self::D, self::SPACE_MANUAL),
            ],
            ['simul' => [
                self::A => ['actArrangeTop', [$a[1]->id, $a[0]->id]],
                self::B => ['actPickOne', $b[1]->id],
                self::C => ['actTopOrBottom', 'bottom'],
                self::D => ['actPickFromDeck', $d[3]->id],
            ]],
        );

        self::assertSame(['simul', 'done'], $result['transitions']);
        // 005: 上の 2 枚を逆順に。052: 選んだ B[1] が上、残りの B[0] は山札の下。010: 見たカードを下へ。050: 選んだカードが一番上
        self::assertSame([$a[1]->id, $a[0]->id], array_map(static fn(Card $x): int => $x->id, $this->cards->peekTop(Zone::Deck, self::A, 2)));
        self::assertSame($b[1]->id, $this->cards->peekTop(Zone::Deck, self::B, 1)[0]->id);
        self::assertSame($b[0]->id, $this->cards->list(Zone::Deck, self::B)[0]->id);
        self::assertSame($c[1]->id, $this->cards->peekTop(Zone::Deck, self::C, 1)[0]->id);
        self::assertSame($c[0]->id, $this->cards->list(Zone::Deck, self::C)[0]->id);
        self::assertSame($d[3]->id, $this->cards->peekTop(Zone::Deck, self::D, 1)[0]->id);
        // 見たカード・選んだカードは公開通知に載らない（N19）
        foreach (self::named($result['notes'], 'deckLooked') as $n) {
            self::assertArrayNotHasKey('card', $n->args);
            self::assertArrayNotHasKey('position', $n->args);
        }
        self::assertCount(4, self::named($result['notes'], 'deckLooked'));
    }

    /** 候補が 0 枚の 020 は、選ばせずにシャッフルだけを自動で行う（001 Q-28） */
    public function testSearchManualWithoutCandidatesShufflesOnly(): void
    {
        $this->table(2);
        $this->deck(self::A);
        $this->deck(self::B);

        $result = $this->resolve([self::A => $this->drawn(self::A, self::SOLAR_MANUAL), self::B => $this->drawn(self::B, self::ROBOT_ARM)]);

        self::assertSame(['simul', 'done'], $result['transitions']); // 005 は山札 6 枚で選択が要る
        self::assertCount(1, self::named($result['notes'], 'deckShuffled'));
    }

    /** TC-009-1・TC-014-1: 戻して引き直す。新しく引いたカードの効果は発動しない */
    public function testDrillAndRecycler(): void
    {
        $this->table(2);
        $this->deck(self::A, [self::ALBUM, 1, 1, 1, 1, 1]);
        $this->deck(self::B, [self::ALBUM, 1, 1, 1, 1, 1]);
        $this->mk(3, Zone::Prep, self::A, 1);
        $this->mk(1, Zone::Prep, self::B, 1);
        $this->mk(2, Zone::Prep, self::B, 2);

        $result = $this->resolve(
            [self::A => $this->drawn(self::A, self::DRILL, 2), self::B => $this->drawn(self::B, self::RECYCLER, 3)],
            ['simul' => [self::A => ['actYesNo', true], self::B => ['actYesNo', true]]],
        );

        self::assertSame(['simul', 'done'], $result['transitions']);
        // A: 003 を戻して 1 枚準備 → 準備場は 2 枚。B: 001・002 を戻して 2 枚準備 → 準備場は 3 枚
        self::assertSame(2, $this->prepCount(self::A));
        self::assertSame(3, $this->prepCount(self::B));
        // 新しく引いたカード（028 が出ても）は効果が発動しない: 記録は作られない
        self::assertSame([], $this->records()['draw_only']);
        $reasons = array_map(static fn(Notification $n): string => (string) $n->args['reason'], self::named($result['notes'], 'cardDrawn'));
        self::assertSame(['drill', 'recycler', 'recycler'], $reasons);
    }

    /** TC-009-2: 009 が 1 枚目なら対象なしで、状態に入らずシャッフルもしない */
    public function testDrillWithoutPreviousCardDoesNothing(): void
    {
        $this->table(2);
        $this->deck(self::A);
        $this->deck(self::B);

        $result = $this->resolve([self::A => $this->drawn(self::A, self::DRILL, 1), self::B => $this->drawn(self::B, self::ALBUM, 1)]);

        self::assertSame(['done'], $result['transitions']);
        self::assertNotContains('deckShuffled', self::names($result['notes']));
        self::assertSame([self::B], $this->records()['draw_only']);
    }

    /** TC-036-1・TC-036-4: 入れ替える／入れ替えない */
    public function testWormholeSwapAndKeep(): void
    {
        $this->table(2);
        $a = $this->deck(self::A, [self::COST4, 1, 1, 1, 1, 1]);
        $this->deck(self::B);
        $row = $this->row(self::COST4B, 4, 1);

        $swap = $this->resolve(
            [self::A => $this->drawn(self::A, self::WORMHOLE), self::B => $this->drawn(self::B, self::ALBUM)],
            ['wormhole' => $row->id],
        );

        self::assertSame(['wormhole', 'done'], $swap['transitions']);
        $swapped = self::named($swap['notes'], 'wormholeSwapped')[0];
        self::assertSame($row->id, $swapped->args['row_card_id']);
        self::assertSame(4, $swapped->args['cost']);
        self::assertSame(1, $swapped->args['slot']);
        self::assertSame(Zone::Row, $this->cards->get($a[0]->id)->zone);
        self::assertSame(1, $this->cards->get($a[0]->id)->order);
        self::assertSame(6, $this->cards->count(Zone::Deck, self::A)); // 山札の上の 1 枚が列へ、列のカードが山札へ
        self::assertNotContains($row->id, $this->ids(Zone::Deck, self::A)); // 山札に入ったカードの ID は付け替わる（007 §7.4）
    }

    public function testWormholeKeepShufflesAndReturnsRevealedCard(): void
    {
        $this->table(2);
        $this->deck(self::A, [self::COST4, 1, 1, 1, 1, 1]);
        $this->deck(self::B);
        $row = $this->row(self::COST4B, 4, 1);

        $result = $this->resolve(
            [self::A => $this->drawn(self::A, self::WORMHOLE), self::B => $this->drawn(self::B, self::ALBUM)],
            ['wormhole' => null],
        );

        self::assertSame(['wormhole', 'done'], $result['transitions']);
        self::assertSame(Zone::Row, $this->cards->get($row->id)->zone);
        self::assertSame(6, $this->cards->count(Zone::Deck, self::A));
        self::assertContains('wormholeRevealed', self::names($result['notes']));
        self::assertContains('deckShuffled', self::names($result['notes']));
    }

    /** TC-007-1: 007 が保持した列のカードは、持ち主以外は探査できない */
    public function testLaserSightLocksRowCardForOthers(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $x = $this->row(self::COST2, 2, 0);
        $y = $this->row(6, 2, 1);

        $this->resolve(
            [self::A => $this->drawn(self::A, self::LASER), self::B => $this->drawn(self::B, self::ALBUM)],
            ['laser' => $x->id],
        );

        self::assertSame(['player_id' => self::A, 'card_id' => $x->id], $this->globals->lockedCard());
        $explore = new ExplorePhase(
            $this->cards,
            $this->players,
            $this->globals,
            new ExploreOutputCalculator($this->cards, $this->globals),
            static fn(int $id): string => 'P' . $id,
        );
        foreach ([self::A, self::B] as $id) {
            $this->players->setExploreOutput($id, 4);
        }
        $idsFor = static fn(array $targets): array => array_map(static fn(array $t): int => $t['cardId'], $targets['rowTargets']);
        self::assertSame([$x->id, $y->id], $idsFor($explore->targets(self::A)));
        self::assertSame([$y->id], $idsFor($explore->targets(self::B)));
    }

    /** お守りの所持者が違っても、現行カードでは 007 は 1 人だけ。席順の最後でもロックできる */
    public function testLaserSightWithLastSeatHolder(): void
    {
        $this->table(3, self::C);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $x = $this->row(self::COST2, 2, 0);

        $result = $this->resolve(
            [self::B => $this->drawn(self::B, self::LASER), self::A => $this->drawn(self::A, self::ALBUM)],
            ['laser' => $x->id],
        );

        self::assertSame(['laser', 'done'], $result['transitions']);
        self::assertSame(self::B, $this->globals->lockedCard()['player_id'] ?? null);
    }

    /** 007 の候補が列に無いときは状態に入らず、何も記録しない（TC-007-3） */
    public function testLaserSightWithEmptyRow(): void
    {
        $this->table(2);
        $this->deck(self::A);
        $this->deck(self::B);

        $result = $this->resolve([self::A => $this->drawn(self::A, self::LASER), self::B => $this->drawn(self::B, self::ALBUM)]);

        self::assertSame(['done'], $result['transitions']);
        self::assertNull($this->globals->lockedCard());
    }

    // ---- zombie（006 §7） ----

    public function testZombieChoices(): void
    {
        $this->table(3);
        $this->deck(self::A);
        $this->deck(self::B, [self::COST4, 1, 1, 1, 1, 1]);
        $this->deck(self::C);
        $this->players->setPrepFinished(self::C, true);
        $fuel = $this->row(self::FUEL, 4, 0);
        $album = $this->row(self::ALBUM, 4, 1);
        $this->row(self::COST4B, 4, 2);

        $this->resolver->enqueue([
            self::A => $this->drawn(self::A, self::SATELLITE),
            self::B => $this->drawn(self::B, self::WORMHOLE),
        ]);
        // 008: 候補（024・028）の最小 ID を選ぶ。選んだ 024 は P3 で解決される
        self::assertSame('copy', $this->resolver->advance()->transition);
        $this->resolver->zombieCopy(self::A);
        self::assertSame('wormhole', $this->resolver->advance()->transition);
        // 036: 入れ替えない
        $this->resolver->zombieWormhole(self::B);
        self::assertSame('done', $this->resolver->advance()->transition);
        self::assertSame(1, $this->prepCount(self::C));
        self::assertSame(Zone::Row, $this->cards->get($fuel->id)->zone);
        self::assertSame(Zone::Row, $this->cards->get($album->id)->zone);
    }

    public function testZombieDoesNotReturnOrLock(): void
    {
        $this->table(3);
        foreach ($this->ids as $id) {
            $this->deck($id);
        }
        $x = $this->row(self::COST2, 2);

        $this->resolver->enqueue([
            self::A => $this->drawn(self::A, self::POD),
            self::B => $this->drawn(self::B, self::ALBUM),
            self::C => $this->drawn(self::C, self::LASER),
        ]);
        self::assertSame('pod', $this->resolver->advance()->transition);
        foreach ($this->resolver->podTargetPlayers() as $id) {
            $this->resolver->decideReturn($id, false);
        }
        self::assertSame('laser', $this->resolver->advance()->transition);
        $this->resolver->zombieLaser(self::C);
        self::assertSame('done', $this->resolver->advance()->transition);
        self::assertNull($this->globals->lockedCard());
        self::assertSame(0, $this->cards->count(Zone::Removed));
        self::assertSame(Zone::Row, $this->cards->get($x->id)->zone);
    }

    public function testZombieSimultaneousEffectsKeepDeckAndPrep(): void
    {
        $this->table(3);
        $a = $this->deck(self::A, [2, 3, 1, 1, 1, 1]);
        $b = $this->deck(self::B, [2, 3, 1, 1, 1, 1]);
        $c = $this->deck(self::C, [2, 3, 1, 1, 1, 1]);
        $this->resolver->enqueue([
            self::A => $this->drawn(self::A, self::ROBOT_ARM),
            self::B => $this->drawn(self::B, self::SCALES),
            self::C => $this->drawn(self::C, self::MANUAL_010),
        ]);
        self::assertSame('simul', $this->resolver->advance()->transition);
        foreach ([self::A, self::B, self::C] as $id) {
            $this->resolver->simulZombie($id);
        }
        self::assertSame('done', $this->resolver->advance()->transition);
        // 005・052・010 は見た順のまま・上のまま
        self::assertSame([$a[0]->id, $a[1]->id], array_map(static fn(Card $x): int => $x->id, $this->cards->peekTop(Zone::Deck, self::A, 2)));
        self::assertSame($b[0]->id, $this->cards->peekTop(Zone::Deck, self::B, 1)[0]->id);
        self::assertSame($c[0]->id, $this->cards->peekTop(Zone::Deck, self::C, 1)[0]->id);
    }

    // ---- 20 の読み取り側へのつなぎ ----

    /** 開示（24）から解決までつなぐ: 2 人がドロー、1 人がストップ */
    public function testRevealToResolveFlow(): void
    {
        $this->table(3);
        $this->deck(self::A, [self::ALBUM, 1, 1, 1, 1, 1]);
        $this->deck(self::B, [self::SAFETY, 1, 1, 1, 1, 1]);
        $this->deck(self::C);
        $prep = new PrepPhase($this->cards, $this->players, $this->globals, static fn(int $id): string => 'P' . $id);

        $prep->begin($this->ids);
        $prep->select(self::A, PrepChoice::Draw);
        $prep->select(self::B, PrepChoice::Draw);
        $prep->select(self::C, PrepChoice::Stop);
        $prep->lock($this->ids);
        $reveal = $prep->reveal($this->ids);
        self::assertSame('resolve', $reveal->transition);
        self::assertSame([self::A, self::B], array_keys($reveal->drawn));

        $this->resolver->enqueue($reveal->drawn);
        $step = $this->resolver->advance();
        self::assertSame('done', $step->transition);
        self::assertSame(
            ['force_draw' => false, 'draw_only' => [self::A], 'stop_only' => [self::B], 'late' => null],
            $this->records(),
        );

        // 次の 20 で記録を読む。A はドロー確定、B はストップ確定、C は準備終了済みで選択者にならない
        $prep->begin($this->ids);
        self::assertSame(PrepChoice::Draw, $this->players->prepChoice(self::A));
        self::assertSame(PrepChoice::Stop, $this->players->prepChoice(self::B));
        self::assertSame([], $this->globals->prepSelectors());
        self::assertSame($this->resolver->advance()->transition, 'done');
    }
}
