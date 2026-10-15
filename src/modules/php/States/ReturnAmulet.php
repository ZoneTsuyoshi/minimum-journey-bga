<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 帰還フェイズ①：お守りの移動（006 §2.5、001 §7-1）。
 * 058 の獲得時効果（遷移 exclude → 51）は I-11 までは使わず、常に next → 52 へ進む。
 */
class ReturnAmulet extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_AMULET,
            type: StateType::GAME,
            transitions: [
                'exclude' => StateId::AMULET_EXCLUDE_CHOICE,
                'next' => StateId::RETURN_SHUFFLE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $this->game->sendNotifications($this->game->returnPhase->moveCharm($this->game->seatOrder()));

        return 'next';
    }
}
