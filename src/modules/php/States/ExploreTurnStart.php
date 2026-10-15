<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 次の探査手番のプレイヤーを決める（006 §6.1）。全員終わっていれば帰還フェイズへ。
 * 骨格では探査時効果（42）は経由しない（I-11）。
 */
class ExploreTurnStart extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::EXPLORE_TURN_START,
            type: StateType::GAME,
            transitions: [
                'onExplore' => StateId::EXPLORE_ON_EXPLORE_CHOICE,
                'choose' => StateId::EXPLORE_CHOOSE,
                'allDone' => StateId::RETURN_AMULET,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $globals = $this->game->globalValues;
        $playerId = RoundFlow::currentExplorer($globals->exploreOrder(), $globals->exploreIndex());
        if ($playerId === null) {
            return 'allDone';
        }

        $this->game->gamestate->changeActivePlayer($playerId);
        $globals->setExploreState([
            'player_id' => $playerId,
            'sum_cost' => 0,
            'explored' => 0,
            'used_on_explore' => [],
            'additional_done' => false,
            'cost_mod' => 0,
        ]);

        return 'choose';
    }
}
