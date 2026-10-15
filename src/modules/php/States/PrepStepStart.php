<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 準備選択 1 回分の開始（006 §2.2）。
 * 054 の打ち消し、028・029・山札 0 枚の自動確定、049 の後選びの分類と、記録（next_choice_records）の消費を行う。
 * 記録を書くのはドロー時効果（I-09）。ここは読む側だけ。
 */
class PrepStepStart extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::PREP_STEP_START,
            type: StateType::GAME,
            transitions: [
                'needChoice' => StateId::PREP_CHOICE,
                'skipToLock' => StateId::PREP_LOCK,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $result = $this->game->prepPhase->begin($this->game->mjSeatOrder());
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
