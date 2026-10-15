<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 次の探査手番のプレイヤーを決める（006 §6.1）。全員終わっていれば帰還フェイズへ。
 * 探査時効果（42）は I-11 まで経由しない。
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
        $result = $this->game->explorePhase->beginTurn();
        if ($result->playerId !== null) {
            $this->game->gamestate->changeActivePlayer($result->playerId);
        }
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
