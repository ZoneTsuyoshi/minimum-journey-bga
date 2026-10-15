<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 選択の確定（006 §2.2）。049 の後選びがいれば 23 へ、いなければ開示へ。骨格では後選びは起きない。
 */
class PrepLock extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::PREP_LOCK,
            type: StateType::GAME,
            transitions: [
                'lateChoice' => StateId::PREP_CHOICE_LATE,
                'reveal' => StateId::PREP_REVEAL,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return $this->game->globalValues->prepLatePlayer() === null ? 'reveal' : 'lateChoice';
    }
}
