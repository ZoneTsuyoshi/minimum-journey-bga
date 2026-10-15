<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 準備選択 1 回分の開始（006 §2.2）。
 * 骨格では「準備終了していない全員」を選択者にする。028・029・049・054・山札 0 枚の分類は I-05 で足す。
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
        $values = $this->game->playerValues;
        $selectors = RoundFlow::pendingSelectors($values->prepFinishedMap());
        $values->clearPrepChoices();
        $this->game->globalValues->setPrepSelectors($selectors);

        return $selectors === [] ? 'skipToLock' : 'needChoice';
    }
}
