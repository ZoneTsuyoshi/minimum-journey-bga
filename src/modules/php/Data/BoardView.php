<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Setup\GameSetup;

/**
 * getAllDatas に載せる盤面（仕様書 007 §8.2 のうち骨格に必要な分）。
 * 山札・探査山札は枚数のみ。facedown・setup_removed・prep_choice は含めない（007 §8.1）。
 * 準備場（付加カード込み）と獲得カードは公開情報なので全員に返す（007 §8.2）。
 * 除外・名声などは I-05 以降で足す。
 */
final class BoardView
{
    public function __construct(
        private readonly CardRepository $cards,
        private readonly GlobalValues $globals,
        private readonly PlayerValues $players,
        private readonly ?ExploreOutputCalculator $calculator = null,
    ) {
    }

    /**
     * @param list<int> $playerOrder 席順（player_no の昇順）のプレイヤー ID
     * @return array<string, mixed>
     */
    public function build(array $playerOrder): array
    {
        $piles = [];
        $rows = [];
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            $piles[$cost] = $this->cards->count(Zone::ExploreDeck, $cost);
            $rows[$cost] = array_map(
                fn(Card $card): array => ['slot' => $card->order, 'card' => self::cardView($card)],
                $this->cards->list(Zone::Row, $cost),
            );
        }

        $unknown = $this->cards->list(Zone::Unknown);
        $charm = $this->cards->list(Zone::Charm);
        $locked = $this->globals->lockedCard();

        $finished = $this->players->prepFinishedMap();
        $playerData = [];
        foreach ($playerOrder as $playerId) {
            $prep = [];
            foreach ($this->cards->list(Zone::Prep, $playerId) as $card) {
                $prep[] = [
                    'card' => self::cardView($card),
                    'order' => $card->order,
                    'sideways' => $card->sideways,
                    'attached' => array_map(self::cardView(...), $this->cards->attachedTo($card)),
                ];
            }
            $playerData[$playerId] = [
                'prep' => $prep,
                'acquired' => array_map(self::cardView(...), $this->cards->list(Zone::Acquired, $playerId)),
                'deckCount' => $this->cards->count(Zone::Deck, $playerId),
                'facedownCount' => $this->cards->count(Zone::Facedown, $playerId),
                'prepFinished' => $finished[$playerId] ?? false,
                'prepCount' => $this->cards->count(Zone::Prep, $playerId),
                'acquiredCount' => $this->cards->count(Zone::Acquired, $playerId),
                'isHalved' => $this->players->isHalved($playerId),
                'output' => $this->players->exploreOutput($playerId),
                'outputNow' => $this->calculator?->outputNow($playerId) ?? 0,
            ];
        }

        return [
            'round' => $this->globals->roundNo(),
            'phase' => $this->globals->phase(),
            'playerOrder' => $playerOrder,
            'piles' => $piles,
            'rows' => $rows,
            'unknown' => $unknown === [] ? null : self::cardView($unknown[0]),
            'charm' => $charm === [] ? null : ['card' => self::cardView($charm[0]), 'holder' => $charm[0]->locationArg],
            'lockedCard' => $locked === null ? null : ['playerId' => $locked['player_id'], 'cardId' => $locked['card_id']],
            'exploreOrder' => $this->globals->exploreOrder(),
            'exploreIndex' => $this->globals->exploreIndex(),
            'players' => $playerData,
        ];
    }

    /**
     * @return array{id: int, cardNo: int, art: int}
     */
    public static function cardView(Card $card): array
    {
        return ['id' => $card->id, 'cardNo' => $card->cardNo, 'art' => $card->art];
    }
}
