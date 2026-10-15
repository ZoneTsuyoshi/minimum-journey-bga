<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 帰還フェイズ③④：列の補充と終了判定（006 §2.5）。
 * 補充後にコスト 8 の探査山札が空なら終了フラグを立て、終了フラグがあれば 60、なければ次のラウンド（10）へ進む。
 */
class ReturnRefill extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_REFILL,
            type: StateType::GAME,
            updateGameProgression: true,
            transitions: [
                'nextRound' => StateId::ROUND_START,
                'end' => StateId::FINAL_SCORING,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $result = $this->game->returnPhase->refill($this->game->seatOrder());
        $this->game->sendNotifications($result->notifications);
        $this->game->stats->recordRoundFinished();

        return (string) $result->transition;
    }
}
