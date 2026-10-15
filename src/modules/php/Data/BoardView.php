<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

use Bga\Games\MinimumJourney\Setup\GameSetup;

/**
 * getAllDatas に載せる盤面（仕様書 007 §8.2 のうち骨格に必要な分）。
 * 山札・探査山札は枚数のみ。facedown・setup_removed・prep_choice は含めない（007 §8.1）。
 * 準備場・獲得カード・除外・名声・付加カードなどは I-05 以降で足す。
 */
final class BoardView
{
    public function __construct(
        private readonly CardRepository $cards,
        private readonly GlobalValues $globals,
        private readonly PlayerValues $players,
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

        $finished = $this->players->prepFinishedMap();
        $playerData = [];
        foreach ($playerOrder as $playerId) {
            $playerData[$playerId] = [
                'deckCount' => $this->cards->count(Zone::Deck, $playerId),
                'facedownCount' => $this->cards->count(Zone::Facedown, $playerId),
                'prepFinished' => $finished[$playerId] ?? false,
                'output' => $this->players->exploreOutput($playerId),
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
