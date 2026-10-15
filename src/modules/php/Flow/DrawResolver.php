<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PlayerValues;
use Bga\Games\MinimumJourney\Data\ResolveEntry;
use Bga\Games\MinimumJourney\Data\ResolveEntryRepository;
use Bga\Games\MinimumJourney\Data\ResolveLevel;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\DrawLevel;
use Bga\Games\MinimumJourney\Material\EffectDefinition;
use Bga\Games\MinimumJourney\Material\EffectKind;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use Bga\Games\MinimumJourney\Material\Symbol;
use Bga\Games\MinimumJourney\Setup\GameSetup;

/**
 * ドロー時効果の解決エンジン（状態 30。010 §6.1、006 §5.2）の DB を使う部分。
 * BGA の Table には依存せず、送る通知は戻り値（DrawStep）で返す。結合テストで直接動かす。
 *
 * resolve_level（カーソル）と resolve_entry（解決キュー）で進める。advance() は自動で解決できるレベルを状態を出ずに
 * 進め、プレイヤーの選択が要るところで割り込み先（'pod' 31／'copy' 32／'wormhole' 33／'laser' 34／'simul' 35）を返す。
 * 割り込み状態のアクションは、このクラスの対応するメソッドを呼んで状態 30 に戻る。
 *
 * @phpstan-import-type Plan from DrawEffects
 */
final class DrawResolver
{
    private const P2A = 25;

    /** @var callable(int): string */
    private $playerName;
    /** @var callable(): list<int> */
    private $seatOrder;
    private readonly DrawEffects $effects;

    /**
     * @param callable(int): string $playerName
     * @param callable(): list<int> $seatOrder 席順（時計回り。D10）のプレイヤー ID
     */
    public function __construct(
        private readonly CardRepository $cards,
        private readonly PlayerValues $players,
        private readonly GlobalValues $globals,
        private readonly ResolveEntryRepository $entries,
        private readonly ExploreOutputCalculator $calculator,
        callable $playerName,
        callable $seatOrder,
    ) {
        $this->playerName = $playerName;
        $this->seatOrder = $seatOrder;
        $this->effects = new DrawEffects($cards, $playerName);
    }

    // ---- 入口 ----

    /**
     * 010 §6.1 手順 3 のキュー構築。このドローで引かれたカードは、効果を持たないものも含めて全て行にする（043 の対象判定に使う）。
     *
     * @param array<int, Card> $drawn プレイヤー ID => 引いたカード
     */
    public function enqueue(array $drawn): void
    {
        $this->entries->clear();
        foreach (($this->seatOrder)() as $playerId) {
            $card = $drawn[$playerId] ?? null;
            if ($card === null) {
                continue;
            }
            $this->insertEntry($playerId, $card, ResolveEntry::ORIGIN_DRAWN, null, ResolveEntry::STATUS_PENDING);
        }
        $this->globals->setResolveLevel(ResolveLevel::Init);
    }

    /**
     * 状態 30 の onEnteringState。割り込みが要るレベルまで進める。
     */
    public function advance(): DrawStep
    {
        $notifications = [];
        while (true) {
            $step = match ($this->globals->resolveLevel()) {
                ResolveLevel::Init => $this->go(ResolveLevel::P0),
                ResolveLevel::P0 => $this->stepP0(),
                ResolveLevel::P1 => $this->stepP1($notifications),
                ResolveLevel::P2 => $this->stepP2(),
                ResolveLevel::P2Wait => $this->stepP2Wait($notifications),
                ResolveLevel::P2A => $this->stepP2a($notifications),
                ResolveLevel::Copy, ResolveLevel::CopyWait => $this->stepCopy(),
                ResolveLevel::P3 => $this->stepP3($notifications),
                ResolveLevel::P4, ResolveLevel::P4Wait => $this->stepP4($notifications),
                ResolveLevel::P5Seq => $this->stepP5Seq(),
                ResolveLevel::P5Sim => $this->stepP5Sim($notifications),
                ResolveLevel::P6 => $this->stepP6($notifications),
            };
            if ($step !== null) {
                return $this->finish($step->transition, [...$notifications, ...$step->notifications], $step->activePlayer);
            }
        }
    }

    /**
     * @param list<Notification> $notifications
     */
    private function finish(?string $transition, array $notifications, ?int $activePlayer = null): DrawStep
    {
        $notifications[] = $this->counters();
        return new DrawStep($transition, $notifications, $activePlayer);
    }

    private function go(ResolveLevel $level): null
    {
        $this->globals->setResolveLevel($level);
        return null;
    }

    // ---- P0・P1 ----

    /**
     * P0: 054 の仮記録（039・053 の影響を受けない。D2）。043 で戻されたら取り消す（H2）ので、確定は P6 で行う。
     */
    private function stepP0(): null
    {
        $this->globals->setForceDrawTmp($this->forceDrawEntry() !== null);
        return $this->go(ResolveLevel::P1);
    }

    /**
     * P1: 抑止の判定（同時。無効になっても抑止作用は残る。F3）。
     *
     * @param list<Notification> $notifications
     */
    private function stepP1(array &$notifications): null
    {
        array_push($notifications, ...$this->applyFilters(null));
        return $this->go(ResolveLevel::P2);
    }

    /**
     * 抑止カード（039・053）の条件を、054 を除く効果を持つ全ての行に適用する。
     * $only を渡すと、その抑止カードだけを、まだ解決していない効果（P2a より後のレベル）に適用する（046 の付加カード。J2）。
     *
     * @return list<Notification>
     */
    private function applyFilters(?ResolveEntry $only): array
    {
        $all = $this->entries->all();
        $filters = $only !== null
            ? [$only]
            : array_values(array_filter($all, static fn(ResolveEntry $e): bool => self::isFilter($e)));
        $notifications = [];
        foreach ($all as $entry) {
            if ($entry->level === null) {
                continue;
            }
            if (self::definitionEffect($entry->cardNo)->immuneToSuppression) {
                if ($entry->status === ResolveEntry::STATUS_PENDING) {
                    $this->entries->setStatus($entry->id, ResolveEntry::STATUS_VALID); // 054 は抑止されない（D2）
                }
                continue;
            }
            $settled = [ResolveEntry::STATUS_INVALID, ResolveEntry::STATUS_RETURNED, ResolveEntry::STATUS_DONE];
            if (in_array($entry->status, $settled, true)) {
                continue;
            }
            if ($only !== null && ($entry->id === $only->id || $entry->level <= self::P2A)) {
                continue; // 決着済みの効果には遡らない（J2）
            }
            $blocker = null;
            foreach ($filters as $filter) {
                $rule = (string) self::definitionEffect($filter->cardNo)->suppressRule;
                $symbols = DrawEffects::definitionOf($entry->cardNo)->symbols ?? [];
                if (!DrawRules::passes($rule, $symbols, $entry->player, $filter->player)) {
                    $blocker = $filter;
                    break;
                }
            }
            if ($blocker === null) {
                $this->entries->setStatus($entry->id, ResolveEntry::STATUS_VALID);
                continue;
            }
            $this->entries->setStatus($entry->id, ResolveEntry::STATUS_INVALID);
            $notifications[] = new Notification(
                null,
                'effectSuppressed',
                clienttranslate('The draw effect of ${card_name} (${player_name}) is cancelled by ${source_name}'),
                [
                    'player_id' => $entry->player,
                    'player_name' => ($this->playerName)($entry->player),
                    'card_id' => $entry->cardId,
                    'card_name' => DrawEffects::nameOf($entry->cardNo),
                    'source_card_id' => $blocker->cardId,
                    'source_name' => DrawEffects::nameOf($blocker->cardNo),
                    'i18n' => ['card_name', 'source_name'],
                ],
            );
        }
        return $notifications;
    }

    // ---- P2: 043 ----

    private function stepP2(): ?DrawStep
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->effectNo() !== 43 || !$entry->isValid()) {
                continue;
            }
            if ($this->podTargets($entry) === []) {
                $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
                continue;
            }
            $this->globals->setResolveLevel(ResolveLevel::P2Wait);
            return new DrawStep('pod', []);
        }
        return $this->go(ResolveLevel::P2A);
    }

    /**
     * 043 の対象になる行（持ち主以外で、この回にカードを引いて、まだ戻していない者）。
     *
     * @return list<ResolveEntry>
     */
    private function podTargets(ResolveEntry $pod): array
    {
        return array_values(array_filter(
            $this->entries->all(),
            static fn(ResolveEntry $e): bool => $e->origin === ResolveEntry::ORIGIN_DRAWN
                && $e->status !== ResolveEntry::STATUS_RETURNED
                && $e->cardId !== null
                && $e->player !== $pod->player,
        ));
    }

    /**
     * 31 に入るべき 043 の行（有効で、まだ適用していないもの）。
     */
    private function pendingPod(): ?ResolveEntry
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->effectNo() === 43 && $entry->isValid()) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * 31 で決める人（席順）。
     *
     * @return list<int>
     */
    public function podTargetPlayers(): array
    {
        $pod = $this->pendingPod();
        if ($pod === null) {
            return [];
        }
        return array_map(static fn(ResolveEntry $e): int => $e->player, $this->podTargets($pod));
    }

    public function podOwner(): ?int
    {
        return $this->pendingPod()?->player;
    }

    /**
     * 決定済みの人（内容は含まない）。
     *
     * @return list<int>
     */
    public function podDecidedPlayers(): array
    {
        $pod = $this->pendingPod();
        if ($pod === null) {
            return [];
        }
        $decided = [];
        foreach ($this->podTargets($pod) as $entry) {
            if ($entry->decision !== null) {
                $decided[] = $entry->player;
            }
        }
        return $decided;
    }

    /**
     * 本人の決定（null＝未決定）。本人以外には返さないこと（H8）。
     */
    public function podDecisionOf(int $playerId): ?bool
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->origin === ResolveEntry::ORIGIN_DRAWN && $entry->player === $playerId && $entry->decision !== null) {
                return $entry->decision === 1;
            }
        }
        return null;
    }

    /**
     * 本人がこの回に引いたカード（公開情報）。
     */
    public function drawnCardOf(int $playerId): ?Card
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->origin === ResolveEntry::ORIGIN_DRAWN && $entry->player === $playerId && $entry->cardId !== null) {
                return $this->cards->get($entry->cardId);
            }
        }
        return null;
    }

    /**
     * 31 の actDecideReturn。押した時点で確定する。決定の内容は載せない（H8）。
     *
     * @throws DrawRuleViolation
     */
    public function decideReturn(int $playerId, bool $return): DrawStep
    {
        $pod = $this->pendingPod();
        $target = null;
        if ($pod !== null) {
            foreach ($this->podTargets($pod) as $entry) {
                if ($entry->player === $playerId) {
                    $target = $entry;
                }
            }
        }
        if ($target === null) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        if ($target->decision !== null) {
            throw new DrawRuleViolation(DrawRuleViolation::ALREADY_DECIDED);
        }
        $this->entries->setDecision($target->id, $return ? 1 : 0);
        return new DrawStep(null, [new Notification(null, 'podDecided', '', [
            'player_id' => $playerId,
            'player_name' => ($this->playerName)($playerId),
        ])]);
    }

    /**
     * P2 の適用: 全員の決定が出そろった後に、戻した人のカードを山札へ → シャッフル → 1 枚準備する（効果は発動しない。D7）。
     *
     * @param list<Notification> $notifications
     */
    private function stepP2Wait(array &$notifications): null
    {
        $pod = $this->pendingPod();
        if ($pod !== null) {
            $targets = $this->podTargets($pod);
            $byPlayer = [];
            foreach ($targets as $entry) {
                $byPlayer[$entry->player] = $entry;
            }
            foreach (($this->seatOrder)() as $playerId) {
                $entry = $byPlayer[$playerId] ?? null;
                if ($entry === null || $entry->cardId === null) {
                    continue;
                }
                if ($entry->decision === 1) {
                    array_push($notifications, ...$this->returnDrawnCard($entry));
                } else {
                    $notifications[] = new Notification(null, 'podKept', clienttranslate('${player_name} keeps the drawn card'), [
                        'player_id' => $playerId,
                        'player_name' => ($this->playerName)($playerId),
                    ]);
                }
            }
            $this->entries->clearDecisions();
            $this->entries->setStatus($pod->id, ResolveEntry::STATUS_DONE);
        }
        return $this->go(ResolveLevel::P2A);
    }

    /**
     * @return list<Notification>
     */
    private function returnDrawnCard(ResolveEntry $entry): array
    {
        $card = $this->cards->get((int) $entry->cardId);
        $attached = $this->cards->attachedTo($card)[0] ?? null;
        $notifications = [$this->effects->returned($entry->player, $card, $attached?->id, 'pod')];
        $this->cards->returnToDeck([$card], $entry->player, true);
        $this->entries->setStatus($entry->id, ResolveEntry::STATUS_RETURNED);
        $this->entries->setCardId($entry->id, null);
        $notifications[] = $this->effects->shuffled($entry->player);
        $drawn = $this->cards->drawTopToPrep($entry->player);
        if ($drawn !== null) {
            $notifications[] = $this->effects->cardDrawn($entry->player, $drawn, 'pod', '');
        }
        return $notifications;
    }

    // ---- P2a: 046 ----

    /**
     * @param list<Notification> $notifications
     */
    private function stepP2a(array &$notifications): ?DrawStep
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->cardNo !== CardRepository::ATTACH_HOST_CARD_NO || !$entry->isValid()) {
                continue;
            }
            $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
            $host = $entry->cardId === null ? null : $this->cards->get($entry->cardId);
            $top = $this->cards->peekTop(Zone::Deck, $entry->player, 1)[0] ?? null;
            if ($host === null || $top === null) {
                continue; // 山札 0 枚なら何もしない（002 No.50 (f)）
            }
            $this->cards->attach($host, $top);
            $attached = $this->cards->get($top->id);
            $notifications[] = new Notification(
                null,
                'attachedCardDrawn',
                clienttranslate('${player_name} places ${card_name} beside ${host_name}'),
                [
                    'player_id' => $entry->player,
                    'player_name' => ($this->playerName)($entry->player),
                    'host_card_id' => $host->id,
                    'host_name' => DrawEffects::nameOf($host->cardNo),
                    'card' => DrawEffects::cardView($attached),
                    'card_name' => DrawEffects::nameOf($attached->cardNo),
                    'i18n' => ['host_name', 'card_name'],
                ],
            );
            $newId = $this->insertEntry($entry->player, $attached, ResolveEntry::ORIGIN_ATTACHED, $entry->id, ResolveEntry::STATUS_VALID);
            $new = $this->entries->get($newId);
            if (self::isFilter($new)) {
                array_push($notifications, ...$this->applyFilters($new));
            }
            if ($new->effectNo() === 43 && $this->podTargets($new) !== []) {
                $this->globals->setResolveLevel(ResolveLevel::P2Wait);
                return new DrawStep('pod', []);
            }
        }
        return $this->go(ResolveLevel::Copy);
    }

    // ---- 008 のコピー元（K1: P2a の直後、P3 の前） ----

    private function stepCopy(): ?DrawStep
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->cardNo !== 8 || !$entry->isValid() || $entry->copyNo !== null) {
                continue;
            }
            if ($this->copyCandidates() === []) {
                $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
                continue;
            }
            $this->globals->setResolveLevel(ResolveLevel::Copy);
            return new DrawStep('copy', [], $entry->player);
        }
        return $this->go(ResolveLevel::P3);
    }

    /**
     * コピー元の候補: 探査場の列の、[太陽系]または[天の川銀河]を持ち【ドロー時】効果を持つカード（002 No.28 (d)）。
     *
     * @return list<Card>
     */
    public function copyCandidates(): array
    {
        $result = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            foreach ($this->cards->list(Zone::Row, $cost) as $card) {
                $definition = DrawEffects::definitionOf($card->cardNo);
                if ($definition === null || $definition->effectsFor(EffectTiming::OnDraw) === []) {
                    continue;
                }
                if ($definition->hasSymbol(Symbol::SolarSystem) || $definition->hasSymbol(Symbol::MilkyWay)) {
                    $result[] = $card;
                }
            }
        }
        return $result;
    }

    private function pendingCopy(): ?ResolveEntry
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->cardNo === 8 && $entry->isValid() && $entry->copyNo === null) {
                return $entry;
            }
        }
        return null;
    }

    public function copyOwner(): ?int
    {
        return $this->pendingCopy()?->player;
    }

    public function copyCardId(): ?int
    {
        return $this->pendingCopy()?->cardId;
    }

    /**
     * 32 の actChooseCopySource。選んだカードのレベルが 008 の解決レベルになる（H5）。
     *
     * @throws DrawRuleViolation
     */
    public function chooseCopy(int $playerId, int $cardId): DrawStep
    {
        $entry = $this->pendingCopy();
        if ($entry === null || $entry->player !== $playerId) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        $source = null;
        foreach ($this->copyCandidates() as $card) {
            if ($card->id === $cardId) {
                $source = $card;
            }
        }
        if ($source === null) {
            throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
        }
        $level = self::levelOf($source->cardNo);
        $this->entries->setCopy($entry->id, $source->cardNo, (int) $level);
        return new DrawStep(null, [new Notification(
            null,
            'copySourceChosen',
            clienttranslate('${player_name} copies ${source_name} with ${card_name}'),
            [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card_id' => $entry->cardId,
                'source' => DrawEffects::cardView($source),
                'card_name' => DrawEffects::nameOf(8),
                'source_name' => DrawEffects::nameOf($source->cardNo),
                'i18n' => ['card_name', 'source_name'],
            ],
        )]);
    }

    /**
     * zombie の選択（006 §7）。候補の最小 ID。
     *
     * @throws DrawRuleViolation
     */
    public function zombieCopy(int $playerId): DrawStep
    {
        $ids = array_map(static fn(Card $c): int => $c->id, $this->copyCandidates());
        sort($ids);
        return $this->chooseCopy($playerId, $ids[0] ?? 0);
    }

    // ---- P3: 024 ----

    /**
     * @param list<Notification> $notifications
     */
    private function stepP3(array &$notifications): null
    {
        foreach ($this->pendingAt(DrawLevel::P3) as $entry) {
            $sourceName = DrawEffects::nameOf($entry->effectNo());
            foreach (($this->seatOrder)() as $playerId) {
                if (!$this->players->prepFinished($playerId)) {
                    continue; // 054 の強制ドローの回は準備終了が打ち消されているので、誰にも作用しない（N38）
                }
                $drawn = $this->cards->drawTopToPrep($playerId);
                if ($drawn !== null) {
                    $notifications[] = $this->effects->cardDrawn($playerId, $drawn, 'fuel', $sourceName);
                }
            }
            $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
        }
        return $this->go(ResolveLevel::P4);
    }

    // ---- P4: 036 ----

    /**
     * @param list<Notification> $notifications
     */
    private function stepP4(array &$notifications): ?DrawStep
    {
        foreach ($this->pendingAt(DrawLevel::P4) as $entry) {
            $top = $this->cards->peekTop(Zone::Deck, $entry->player, 1)[0] ?? null;
            if ($top === null) {
                $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
                continue;
            }
            $notifications[] = new Notification(
                null,
                'wormholeRevealed',
                clienttranslate('${player_name} reveals ${card_name} from the top of their deck'),
                [
                    'player_id' => $entry->player,
                    'player_name' => ($this->playerName)($entry->player),
                    'card' => DrawEffects::cardView($top),
                    'card_name' => DrawEffects::nameOf($top->cardNo),
                    'i18n' => ['card_name'],
                ],
            );
            if ($this->wormholeCandidates($top) === []) {
                array_push($notifications, ...$this->wormholeKeep($entry, $top));
                continue;
            }
            $this->globals->setResolveLevel(ResolveLevel::P4);
            return new DrawStep('wormhole', [], $entry->player);
        }
        return $this->go(ResolveLevel::P5Seq);
    }

    /**
     * 36 の入れ替え候補: 列にある、表にしたカードと印字の探査コストが同じカード（002 No.42 (a)）。
     *
     * @return list<Card>
     */
    public function wormholeCandidates(Card $revealed): array
    {
        $cost = DrawEffects::definitionOf($revealed->cardNo)->cost ?? 0;
        if ($cost <= 0) {
            return [];
        }
        return $this->cards->list(Zone::Row, $cost);
    }

    public function wormholeOwner(): ?int
    {
        return $this->pendingAt(DrawLevel::P4)[0]->player ?? null;
    }

    /**
     * 表にした山札の上のカード（公開情報）。
     */
    public function wormholeRevealed(): ?Card
    {
        $owner = $this->wormholeOwner();
        return $owner === null ? null : ($this->cards->peekTop(Zone::Deck, $owner, 1)[0] ?? null);
    }

    /**
     * 33 の actWormhole。null は入れ替えない（表にしたカードを山札へ戻してシャッフルする）。
     *
     * @throws DrawRuleViolation
     */
    public function wormhole(int $playerId, ?int $rowCardId): DrawStep
    {
        $entry = $this->pendingAt(DrawLevel::P4)[0] ?? null;
        $top = $this->wormholeRevealed();
        if ($entry === null || $entry->player !== $playerId || $top === null) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        if ($rowCardId === null) {
            return new DrawStep(null, $this->wormholeKeep($entry, $top));
        }
        $row = null;
        foreach ($this->wormholeCandidates($top) as $card) {
            if ($card->id === $rowCardId) {
                $row = $card;
            }
        }
        if ($row === null) {
            throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
        }
        $notifications = [new Notification(
            null,
            'wormholeSwapped',
            clienttranslate('${player_name} swaps ${card_name} with ${row_card_name} in the ${cost}-cost row'),
            [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card' => DrawEffects::cardView($top),
                'card_name' => DrawEffects::nameOf($top->cardNo),
                'cost' => $row->locationArg,
                'slot' => $row->order,
                'row_card_id' => $row->id,
                'row_card_name' => DrawEffects::nameOf($row->cardNo),
                'i18n' => ['card_name', 'row_card_name'],
            ],
        )];
        $this->cards->move($top, Zone::Row, $row->locationArg, $row->order);
        $this->cards->returnToDeck([$row], $playerId, true);
        $notifications[] = $this->effects->shuffled($playerId);
        $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
        return new DrawStep(null, $notifications);
    }

    /**
     * 入れ替えない場合: 表にしたカードを山札へ戻してシャッフルする（省略しない。002 No.42 (e)）。
     *
     * @return list<Notification>
     */
    private function wormholeKeep(ResolveEntry $entry, Card $top): array
    {
        $notifications = [$this->effects->returned($entry->player, $top, null, 'wormhole')];
        $this->cards->returnToDeck([$top], $entry->player, true);
        $notifications[] = $this->effects->shuffled($entry->player);
        $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
        return $notifications;
    }

    /**
     * zombie は入れ替えない（006 §7）。
     *
     * @throws DrawRuleViolation
     */
    public function zombieWormhole(int $playerId): DrawStep
    {
        return $this->wormhole($playerId, null);
    }

    // ---- P5-順番: 007 ----

    private function stepP5Seq(): ?DrawStep
    {
        foreach ($this->laserPending() as $entry) {
            if ($this->laserCandidates() === []) {
                $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
                continue;
            }
            return new DrawStep('laser', [], $entry->player);
        }
        return $this->go(ResolveLevel::P5Sim);
    }

    /**
     * 007 の候補: 探査場の列のカード（未確認帯域は含まない）。
     *
     * @return list<Card>
     */
    public function laserCandidates(): array
    {
        $result = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            array_push($result, ...$this->cards->list(Zone::Row, $cost));
        }
        return $result;
    }

    public function laserOwner(): ?int
    {
        return $this->laserPending()[0]->player ?? null;
    }

    /**
     * 解決待ちの 007（D4: お守りから遠いプレイヤーから反時計回り、所持者が最後）。
     *
     * @return list<ResolveEntry>
     */
    private function laserPending(): array
    {
        $pending = $this->pendingAt(DrawLevel::P5Seq);
        $order = DrawRules::farthestFirst(($this->seatOrder)(), $this->amuletHolder());
        usort(
            $pending,
            static fn(ResolveEntry $a, ResolveEntry $b): int => array_search($a->player, $order, true) <=> array_search($b->player, $order, true),
        );
        return $pending;
    }

    /**
     * 34 の actChooseLockedCard。選んだカードを持ち主つきで記録する（ラウンドの間有効）。
     *
     * @throws DrawRuleViolation
     */
    public function chooseLockedCard(int $playerId, int $cardId): DrawStep
    {
        $entry = $this->laserOwner() === $playerId ? $this->entryOf($playerId, DrawLevel::P5Seq) : null;
        if ($entry === null) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        $picked = null;
        foreach ($this->laserCandidates() as $card) {
            if ($card->id === $cardId) {
                $picked = $card;
            }
        }
        if ($picked === null) {
            throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
        }
        $this->globals->setLockedCard(['player_id' => $playerId, 'card_id' => $picked->id]);
        $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
        return new DrawStep(null, [new Notification(
            null,
            'laserSightLocked',
            clienttranslate('${player_name} reserves ${card_name} for this round'),
            [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card' => DrawEffects::cardView($picked),
                'card_name' => DrawEffects::nameOf($picked->cardNo),
                'i18n' => ['card_name'],
            ],
        )]);
    }

    /**
     * zombie が 007 を持つ場合は対象を選ばない（ロックしない。006 §7）。
     *
     * @throws DrawRuleViolation
     */
    public function zombieLaser(int $playerId): DrawStep
    {
        $entry = $this->laserOwner() === $playerId ? $this->entryOf($playerId, DrawLevel::P5Seq) : null;
        if ($entry === null) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
        return new DrawStep(null, []);
    }

    // ---- P5-同時: 005・009・010・014・020・035・050・052 ----

    /**
     * @param list<Notification> $notifications
     */
    private function stepP5Sim(array &$notifications): ?DrawStep
    {
        $waiting = false;
        foreach ($this->pendingAt(DrawLevel::P5Sim) as $entry) {
            if ($this->effects->plan($entry, $this->anchorOf($entry)) === null) {
                array_push($notifications, ...$this->effects->auto($entry));
                $this->entries->setStatus($entry->id, ResolveEntry::STATUS_DONE);
            } else {
                $waiting = true;
            }
        }
        $this->globals->setResolveLevel(ResolveLevel::P6);
        return $waiting ? new DrawStep('simul', []) : null;
    }

    /**
     * 35 で選ぶ人と、その計画（席順）。
     *
     * @return array<int, array{entry: ResolveEntry, anchor: Card, plan: Plan}>
     */
    public function simulRequests(): array
    {
        $result = [];
        foreach ($this->pendingAt(DrawLevel::P5Sim) as $entry) {
            $anchor = $this->anchorOf($entry);
            $plan = $this->effects->plan($entry, $anchor);
            if ($plan !== null) {
                $result[$entry->player] = ['entry' => $entry, 'anchor' => $anchor, 'plan' => $plan];
            }
        }
        return $result;
    }

    /**
     * 35 のアクション（actArrangeTop／actYesNo／actTopOrBottom／actPickFromDeck／actPickOne）。
     *
     * @throws DrawRuleViolation
     */
    public function simulAction(int $playerId, string $action, mixed $payload): DrawStep
    {
        $request = $this->simulRequests()[$playerId] ?? null;
        if ($request === null) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        $notifications = $this->effects->apply($request['entry'], $request['anchor'], $request['plan'], $action, $payload);
        $this->entries->setStatus($request['entry']->id, ResolveEntry::STATUS_DONE);
        return $this->finish(null, $notifications);
    }

    /**
     * zombie の既定行動（006 §7）。
     *
     * @throws DrawRuleViolation
     */
    public function simulZombie(int $playerId): DrawStep
    {
        $request = $this->simulRequests()[$playerId] ?? null;
        if ($request === null) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        [$action, $payload] = $this->effects->zombieAction($request['plan']);
        return $this->simulAction($playerId, $action, $payload);
    }

    // ---- P6: 次の準備選択への記録 ----

    /**
     * ここまでの解決が全て終わった時点で有効なものだけを記録する（010 手順 10）。054 は 043 で戻されていなければ有効（H2）。
     *
     * @param list<Notification> $notifications
     */
    private function stepP6(array &$notifications): DrawStep
    {
        $records = GlobalValues::emptyNextChoiceRecords();
        $force = $this->forceDrawEntry();
        if ($force !== null) {
            $records['force_draw'] = true;
            $notifications[] = $this->recorded('forceDraw', $force);
        }
        foreach ($this->pendingAt(DrawLevel::P6) as $entry) {
            $mode = (string) (DrawEffects::effectOf($entry)->params['mode'] ?? '');
            switch ($mode) {
                case 'DRAW_ONLY':
                    $records['draw_only'][] = $entry->player;
                    $notifications[] = $this->recorded('drawOnly', $entry);
                    break;
                case 'STOP_ONLY':
                    $records['stop_only'][] = $entry->player;
                    $notifications[] = $this->recorded('stopOnly', $entry);
                    break;
                case 'LATE_CHOICE':
                    $records['late'] = $entry->player;
                    $notifications[] = $this->recorded('late', $entry);
                    break;
            }
        }
        $this->globals->setNextChoiceRecords($records);
        $this->globals->setForceDrawTmp(false);
        $this->entries->clear();
        $this->globals->setResolveLevel(ResolveLevel::Init);
        return new DrawStep('done', []);
    }

    private function recorded(string $kind, ResolveEntry $entry): Notification
    {
        $message = match ($kind) {
            'forceDraw' => clienttranslate('${player_name}\'s ${card_name} forces everyone to choose Draw next'),
            'drawOnly' => clienttranslate('${player_name} must choose Draw next (${card_name})'),
            'stopOnly' => clienttranslate('${player_name} must choose Stop next (${card_name})'),
            default => clienttranslate('${player_name} will choose after the others next (${card_name})'),
        };
        return new Notification(null, 'nextChoiceRecorded', $message, [
            'kind' => $kind,
            'player_id' => $entry->player,
            'player_name' => ($this->playerName)($entry->player),
            'card_name' => DrawEffects::nameOf($entry->effectNo()),
            'i18n' => ['card_name'],
        ]);
    }

    // ---- 共通 ----

    /**
     * 054 の記録が有効な行（043 で戻されたものは対象外。H2）。
     */
    private function forceDrawEntry(): ?ResolveEntry
    {
        foreach ($this->entries->all() as $entry) {
            if ($entry->cardNo === 54 && $entry->status !== ResolveEntry::STATUS_RETURNED) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * 実効レベルが指定のレベルで、有効で、まだ解決していない行（キューの順）。
     *
     * @return list<ResolveEntry>
     */
    private function pendingAt(DrawLevel $level): array
    {
        return array_values(array_filter(
            $this->entries->all(),
            static fn(ResolveEntry $e): bool => $e->effLevel === $level->order() && $e->isValid(),
        ));
    }

    private function entryOf(int $playerId, DrawLevel $level): ?ResolveEntry
    {
        foreach ($this->pendingAt($level) as $entry) {
            if ($entry->player === $playerId) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * 効果の位置の基準になるカード。付加カードの効果は付け先の 046 の位置で数える。
     */
    private function anchorOf(ResolveEntry $entry): Card
    {
        $source = $entry->origin === ResolveEntry::ORIGIN_ATTACHED && $entry->host !== null
            ? $this->entries->get($entry->host)
            : $entry;
        return $this->cards->get((int) $source->cardId);
    }

    private function amuletHolder(): ?int
    {
        return ($this->cards->list(Zone::Charm)[0] ?? null)?->locationArg;
    }

    private function insertEntry(int $playerId, Card $card, string $origin, ?int $host, string $status): int
    {
        $level = self::levelOf($card->cardNo);
        return $this->entries->insert(
            $playerId,
            $card->id,
            $card->cardNo,
            $origin,
            $host,
            $level,
            $card->cardNo === 8 ? null : $level, // 008 の実効レベルはコピー元を選ぶまで決まらない
            $level === null ? ResolveEntry::STATUS_VALID : $status,
        );
    }

    /**
     * カード自身の優先度の数値コード（007 §4.4）。ドロー時効果を持たないカードは null。
     */
    private static function levelOf(int $cardNo): ?int
    {
        $effect = DrawEffects::definitionOf($cardNo)?->effectsFor(EffectTiming::OnDraw)[0] ?? null;
        return $effect?->drawLevel?->order();
    }

    private static function definitionEffect(int $cardNo): EffectDefinition
    {
        $effect = DrawEffects::definitionOf($cardNo)?->effectsFor(EffectTiming::OnDraw)[0] ?? null;
        if ($effect === null) {
            throw new \LogicException("no draw effect for card $cardNo");
        }
        return $effect;
    }

    private static function isFilter(ResolveEntry $entry): bool
    {
        $effect = DrawEffects::definitionOf($entry->cardNo)?->effectsFor(EffectTiming::OnDraw)[0] ?? null;
        return $effect !== null && $effect->kind === EffectKind::DrawSuppression;
    }

    /**
     * N33 countersUpdate（全員分。山札・裏向き・準備場の枚数と、現在の探査出力）。
     */
    private function counters(): Notification
    {
        $players = [];
        foreach (($this->seatOrder)() as $playerId) {
            $players[$playerId] = [
                'deckCount' => $this->cards->count(Zone::Deck, $playerId),
                'facedownCount' => $this->cards->count(Zone::Facedown, $playerId),
                'prepCount' => $this->cards->count(Zone::Prep, $playerId),
                'outputNow' => $this->calculator->outputNow($playerId),
            ];
        }
        return new Notification(null, 'countersUpdate', '', ['players' => $players]);
    }
}
