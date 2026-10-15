<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 名声計算と同点処理（006 §2.5）。骨格では計算せず（全員 0 点）ゲームを終える。名声の計算は I-07。
 */
class FinalScoring extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::FINAL_SCORING,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): int
    {
        $result = $this->game->returnPhase->finalScoring($this->game->mjSeatOrder());
        $this->game->mjSendNotifications($result->notifications);
        foreach ($result->scores as $playerId => $score) {
            $this->bga->playerScore->set($playerId, $score->fame(), null);
            $this->bga->playerScoreAux->set($playerId, $score->aux, null);
        }

        return StateId::GAME_END;
    }
}
