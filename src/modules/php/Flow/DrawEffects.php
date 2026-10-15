<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\ResolveEntry;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinition;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\EffectDefinition;
use Bga\Games\MinimumJourney\Material\EffectKind;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * P5-同時（状態 35）の効果と、解決で共有する通知の組み立て（010 §6.1 手順 9、008 の各カード）。
 * 005・009・010・014・020・035・050・052（および 008 がコピーしたもの）が対象。自分の山札・準備場だけを操作する。
 *
 * plan() は読み取り専用（006 B5）。apply()／auto() が DB を書く。
 *
 * @phpstan-type Plan array{type: string, seen: list<Card>, candidates: list<Card>, prev: Card|null}
 */
final class DrawEffects
{
    public const TYPE_ARRANGE_TOP = 'arrangeTop';
    public const TYPE_YES_NO = 'yesNo';
    public const TYPE_TOP_OR_BOTTOM = 'topOrBottom';
    public const TYPE_PICK_FROM_DECK = 'pickFromDeck';
    public const TYPE_PICK_ONE = 'pickOne';

    /** @var callable(int): string */
    private $playerName;

    /**
     * @param callable(int): string $playerName
     */
    public function __construct(private readonly CardRepository $cards, callable $playerName)
    {
        $this->playerName = $playerName;
    }

    // ---- 計画（読み取りのみ） ----

    /**
     * 選択が要るなら計画を返す。選択の余地がない（対象なし・1 枚以下など）なら null（auto() で自動解決）。
     *
     * @param Card $anchor 効果を持つカード（付加カードの効果は付け先の 046）。009 の「直前」の基準
     * @return Plan|null
     */
    public function plan(ResolveEntry $entry, Card $anchor): ?array
    {
        $effect = self::effectOf($entry);
        $owner = $entry->player;
        $deckCount = $this->cards->count(Zone::Deck, $owner);

        switch ($effect->kind) {
            case EffectKind::DeckTopPeek:
                $n = (int) ($effect->params['n'] ?? 1);
                if ($deckCount < 2) {
                    return null; // 1 枚以下は並びの選択の余地がない（002 No.24）
                }
                $type = match ($effect->params['mode'] ?? '') {
                    'REORDER' => self::TYPE_ARRANGE_TOP,
                    'TOP_OR_BOTTOM' => self::TYPE_TOP_OR_BOTTOM,
                    default => self::TYPE_PICK_ONE,
                };
                return ['type' => $type, 'seen' => $this->cards->peekTop(Zone::Deck, $owner, $n), 'candidates' => [], 'prev' => null];
            case EffectKind::DeckSearchToTop:
                $candidates = $this->searchCandidates($owner, $effect);
                if ($candidates === []) {
                    return null;
                }
                return ['type' => self::TYPE_PICK_FROM_DECK, 'seen' => [], 'candidates' => $candidates, 'prev' => null];
            case EffectKind::ReturnAndRedraw:
                if (($effect->params['selector'] ?? '') === 'PREV_CARD') {
                    $prev = $this->prevCard($anchor);
                    return $prev === null ? null : ['type' => self::TYPE_YES_NO, 'seen' => [], 'candidates' => [], 'prev' => $prev];
                }
                $targets = $this->symbolCards($owner, self::symbolOf($effect));
                return $targets === [] ? null : ['type' => self::TYPE_YES_NO, 'seen' => [], 'candidates' => $targets, 'prev' => null];
            default:
                return null;
        }
    }

    /**
     * 山札の中の候補（020・035・050）。card_no の昇順で返す（山の順序を出さない。007 §3.5）。
     *
     * @return list<Card>
     */
    private function searchCandidates(int $owner, EffectDefinition $effect): array
    {
        $symbol = self::symbolOf($effect);
        $result = [];
        foreach ($this->cards->list(Zone::Deck, $owner) as $card) {
            if ($symbol === null || self::definitionOf($card->cardNo)?->hasSymbol($symbol)) {
                $result[] = $card;
            }
        }
        usort($result, static fn(Card $a, Card $b): int => [$a->cardNo, $a->art] <=> [$b->cardNo, $b->art]);
        return $result;
    }

    /**
     * 準備場の表向きで指定シンボルを持つカード（014 の対象 E。046 は付加カードのシンボルも持つ）。
     *
     * @return list<Card>
     */
    private function symbolCards(int $owner, ?Symbol $symbol): array
    {
        $result = [];
        foreach ($this->cards->list(Zone::Prep, $owner) as $card) {
            $symbols = self::definitionOf($card->cardNo)->symbols ?? [];
            foreach ($this->cards->attachedTo($card) as $attached) {
                array_push($symbols, ...(self::definitionOf($attached->cardNo)->symbols ?? []));
            }
            if ($symbol !== null && in_array($symbol, $symbols, true)) {
                $result[] = $card;
            }
        }
        return $result;
    }

    /**
     * 009 の対象。準備場の並びでアンカーの 1 つ前の表向きのカード（横向き・付加カードは数えない。R20）。なければ null。
     */
    private function prevCard(Card $anchor): ?Card
    {
        $previous = null;
        foreach ($this->cards->list(Zone::Prep, $anchor->locationArg) as $card) {
            if ($card->id === $anchor->id) {
                return $previous !== null && !$previous->sideways ? $previous : null;
            }
            $previous = $card;
        }
        return null;
    }

    // ---- 解決 ----

    /**
     * 計画が null の効果の自動解決。候補が 0 枚の 020・035・050 はシャッフルだけを行う（001 Q-28）。
     *
     * @return list<Notification>
     */
    public function auto(ResolveEntry $entry): array
    {
        $effect = self::effectOf($entry);
        $owner = $entry->player;
        if ($effect->kind === EffectKind::DeckSearchToTop && $this->cards->count(Zone::Deck, $owner) > 0) {
            $this->cards->shuffleDeck($owner);
            return [$this->deckLooked($owner, 'search', 0, $entry), $this->shuffled($owner)];
        }
        return [];
    }

    /**
     * 選択を適用する。検証は plan と突き合わせて行う。
     *
     * @param Plan $plan
     * @return list<Notification>
     * @throws DrawRuleViolation
     */
    public function apply(ResolveEntry $entry, Card $anchor, array $plan, string $action, mixed $payload): array
    {
        $owner = $entry->player;
        $type = $plan['type'];
        $expected = [
            self::TYPE_ARRANGE_TOP => 'actArrangeTop',
            self::TYPE_YES_NO => 'actYesNo',
            self::TYPE_TOP_OR_BOTTOM => 'actTopOrBottom',
            self::TYPE_PICK_FROM_DECK => 'actPickFromDeck',
            self::TYPE_PICK_ONE => 'actPickOne',
        ][$type];
        if ($action !== $expected) {
            throw new DrawRuleViolation(DrawRuleViolation::NOT_YOUR_CHOICE);
        }
        $seenIds = array_map(static fn(Card $c): int => $c->id, $plan['seen']);

        switch ($type) {
            case self::TYPE_ARRANGE_TOP:
                $order = array_map('intval', (array) $payload);
                if (count($order) !== count($seenIds) || array_diff($order, $seenIds) !== [] || count(array_unique($order)) !== count($order)) {
                    throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
                }
                $byId = [];
                foreach ($plan['seen'] as $card) {
                    $byId[$card->id] = $card;
                }
                foreach (array_reverse($order) as $id) {
                    $this->cards->putOnTop($byId[$id], Zone::Deck, $owner);
                }
                return [$this->deckLooked($owner, 'top', count($order), $entry)];
            case self::TYPE_TOP_OR_BOTTOM:
                if (!in_array($payload, ['top', 'bottom'], true)) {
                    throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
                }
                if ($payload === 'bottom') {
                    $this->cards->putOnBottom($plan['seen'][0], Zone::Deck, $owner);
                }
                return [$this->deckLooked($owner, 'top', 1, $entry)];
            case self::TYPE_PICK_ONE:
                $chosenId = (int) $payload;
                if (!in_array($chosenId, $seenIds, true)) {
                    throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
                }
                foreach ($plan['seen'] as $card) {
                    if ($card->id !== $chosenId) {
                        $this->cards->putOnBottom($card, Zone::Deck, $owner);
                    }
                }
                foreach ($plan['seen'] as $card) {
                    if ($card->id === $chosenId) {
                        $this->cards->putOnTop($card, Zone::Deck, $owner);
                    }
                }
                return [$this->deckLooked($owner, 'top', count($seenIds), $entry)];
            case self::TYPE_PICK_FROM_DECK:
                $notifications = [$this->deckLooked($owner, 'search', 0, $entry)];
                $picked = null;
                if ($payload !== null) {
                    foreach ($plan['candidates'] as $card) {
                        if ($card->id === (int) $payload) {
                            $picked = $card;
                        }
                    }
                    if ($picked === null) {
                        throw new DrawRuleViolation(DrawRuleViolation::INVALID_TARGET);
                    }
                }
                // 選んだカードを脇に置いて残りをシャッフルし、一番上に戻すのと、全体をシャッフルして選んだカードを上に置くのは同じ結果
                $this->cards->shuffleDeck($owner);
                if ($picked !== null) {
                    $this->cards->putOnTop($picked, Zone::Deck, $owner);
                }
                $notifications[] = $this->shuffled($owner);
                return $notifications;
            default:
                return $this->applyReturn($entry, $plan, (bool) $payload);
        }
    }

    /**
     * 009・014: 戻して引き直す。戻さなければ何もせず、シャッフルもしない（「そうした場合」にかかる。008 N-10）。
     *
     * @param Plan $plan
     * @return list<Notification>
     */
    private function applyReturn(ResolveEntry $entry, array $plan, bool $doReturn): array
    {
        if (!$doReturn) {
            return [];
        }
        $owner = $entry->player;
        $targets = $plan['prev'] !== null ? [$plan['prev']] : $plan['candidates'];
        $notifications = [];
        $sourceName = self::nameOf($entry->effectNo());
        $reason = $plan['prev'] !== null ? 'drill' : 'recycler';
        foreach ($targets as $card) {
            $attached = $this->cards->attachedTo($card)[0] ?? null;
            $notifications[] = $this->returned($owner, $card, $attached?->id, $reason);
        }
        $this->cards->returnToDeck($targets, $owner, true);
        $notifications[] = $this->shuffled($owner);
        for ($i = 0; $i < count($targets); $i++) {
            $drawn = $this->cards->drawTopToPrep($owner);
            if ($drawn !== null) {
                $notifications[] = $this->cardDrawn($owner, $drawn, $reason, $sourceName);
            }
        }
        return $notifications;
    }

    /**
     * zombie の既定行動（006 §7）。山札・準備場を変えない選択。
     *
     * @param Plan $plan
     * @return array{0: string, 1: mixed} アクション名とペイロード
     */
    public function zombieAction(array $plan): array
    {
        return match ($plan['type']) {
            self::TYPE_ARRANGE_TOP => ['actArrangeTop', array_map(static fn(Card $c): int => $c->id, $plan['seen'])],
            self::TYPE_PICK_ONE => ['actPickOne', $plan['seen'][0]->id],
            self::TYPE_TOP_OR_BOTTOM => ['actTopOrBottom', 'top'],
            self::TYPE_PICK_FROM_DECK => ['actPickFromDeck', null],
            default => ['actYesNo', false],
        };
    }

    // ---- 通知の組み立て（解決で共有） ----

    public function cardDrawn(int $playerId, Card $card, string $reason, string $sourceName): Notification
    {
        $message = $reason === 'pod'
            ? clienttranslate('${player_name} prepares ${card_name} after returning the drawn card')
            : clienttranslate('${player_name} prepares ${card_name} (${source_name})');
        return new Notification(null, 'cardDrawn', $message, [
            'player_id' => $playerId,
            'player_name' => ($this->playerName)($playerId),
            'card' => self::cardView($card),
            'card_name' => self::nameOf($card->cardNo),
            'card_no' => $card->cardNo,
            'order' => $card->order,
            'reason' => $reason,
            'source_name' => $sourceName,
            'i18n' => ['card_name', 'source_name'],
        ]);
    }

    /**
     * 準備場から山札へ戻すときの通知。古い ID を載せる（戻した後に ID が付け替わる。007 §7.4）。
     */
    public function returned(int $playerId, Card $card, ?int $attachedId, string $reason): Notification
    {
        return new Notification(null, 'cardReturned', clienttranslate('${player_name} returns ${card_name} to their deck'), [
            'player_id' => $playerId,
            'player_name' => ($this->playerName)($playerId),
            'card_id' => $card->id,
            'card_name' => self::nameOf($card->cardNo),
            'attached_id' => $attachedId,
            'reason' => $reason,
            'i18n' => ['card_name'],
        ]);
    }

    public function shuffled(int $playerId): Notification
    {
        return new Notification(null, 'deckShuffled', clienttranslate('${player_name} shuffles their deck'), [
            'player_id' => $playerId,
            'player_name' => ($this->playerName)($playerId),
        ]);
    }

    /**
     * 山札を見た事実だけを公開する（見たカード・選んだカード・置き方は載せない。006 §5.4）。
     */
    private function deckLooked(int $playerId, string $kind, int $count, ResolveEntry $entry): Notification
    {
        $message = $kind === 'top'
            ? clienttranslate('${player_name} looks at the top ${count} card(s) of their deck (${source_name})')
            : clienttranslate('${player_name} searches their deck (${source_name})');
        return new Notification(null, 'deckLooked', $message, [
            'player_id' => $playerId,
            'player_name' => ($this->playerName)($playerId),
            'kind' => $kind,
            'count' => $count,
            'source_name' => self::nameOf($entry->effectNo()),
            'i18n' => ['source_name'],
        ]);
    }

    // ---- 定義の引き ----

    public static function effectOf(ResolveEntry $entry): EffectDefinition
    {
        $definition = self::definitionOf($entry->effectNo());
        $effect = $definition?->effectsFor(EffectTiming::OnDraw)[0] ?? null;
        if ($effect === null) {
            throw new \LogicException('no draw effect for card ' . $entry->effectNo());
        }
        return $effect;
    }

    public static function definitionOf(int $cardNo): ?CardDefinition
    {
        return CardDefinitions::get(sprintf('%03d', $cardNo));
    }

    public static function nameOf(int $cardNo): string
    {
        return self::definitionOf($cardNo)->nameEn ?? '';
    }

    /**
     * @return array{id: int, cardNo: int, art: int}
     */
    public static function cardView(Card $card): array
    {
        return ['id' => $card->id, 'cardNo' => $card->cardNo, 'art' => $card->art];
    }

    private static function symbolOf(EffectDefinition $effect): ?Symbol
    {
        $value = $effect->params['symbol'] ?? null;
        return is_string($value) ? Symbol::tryFrom($value) : null;
    }
}
