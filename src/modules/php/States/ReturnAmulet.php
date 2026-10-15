<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 帰還フェイズ①：お守りの移動（006 §2.5、001 §7-1）。
 * 面が 058 なら、新所有者の獲得時効果（遷移 exclude → 51）。
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
        $result = $this->game->returnPhase->moveCharmStep($this->game->seatOrder());
        $this->game->sendNotifications($result->notifications);
        $charm = $this->game->cards->list(Zone::Charm)[0] ?? null;
        if ($charm !== null) {
            $this->game->stats->recordCharmHolder($charm->locationArg);
        }
        if ($result->transition === 'exclude') {
            $this->game->gamestate->changeActivePlayer((int) $result->playerId);
        }

        return (string) $result->transition;
    }
}
