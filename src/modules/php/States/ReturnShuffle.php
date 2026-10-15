<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 帰還フェイズ②：シャッフル（006 §2.5、001 §7-2）。
 */
class ReturnShuffle extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_SHUFFLE,
            type: StateType::GAME,
            transitions: [
                'next' => StateId::RETURN_REFILL,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $this->game->sendNotifications($this->game->returnPhase->shuffle($this->game->seatOrder()));

        return 'next';
    }
}
