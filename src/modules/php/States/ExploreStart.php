<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 探査出力の決定と探査手番の決定（006 §2.4、§6.1）。
 * 出力は 011 §6 の手順（探査力変動は I-10）で全員分を確定して以後固定し、手番は 001 §6-2 の 3 段階で決める。
 */
class ExploreStart extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::EXPLORE_START,
            type: StateType::GAME,
            transitions: [
                'start' => StateId::EXPLORE_TURN_START,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $result = $this->game->explorePhase->start($this->game->seatOrder());
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
