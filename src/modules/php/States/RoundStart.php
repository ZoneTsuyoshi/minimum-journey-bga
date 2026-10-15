<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * ラウンドの開始（006 §2.1）。ラウンド番号を進め、ラウンド単位のデータを初期化する。
 */
class RoundStart extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::ROUND_START,
            type: StateType::GAME,
            updateGameProgression: true,
            transitions: [
                'toPrep' => StateId::PREP_STEP_START,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $globals = $this->game->globalValues;
        $round = $globals->incRoundNo();
        $globals->setPhase(GlobalValues::PHASE_PREP);
        $globals->setPrepSelectors([]);
        $globals->setPrepLatePlayer(null);
        $globals->setNextChoiceRecords(GlobalValues::emptyNextChoiceRecords());
        $globals->setForceDrawTmp(false);
        $globals->setExploreOrder([]);
        $globals->setExploreIndex(0);
        $globals->setExploreState(null);
        $globals->setLockedCard(null);
        $this->game->playerValues->resetRoundValues();

        $this->bga->notify->all('roundStarted', clienttranslate('Round ${round} begins'), [
            'round' => $round,
        ]);

        return 'toPrep';
    }
}
