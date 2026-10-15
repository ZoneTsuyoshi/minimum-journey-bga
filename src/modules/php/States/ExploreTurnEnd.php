<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 探査手番の終了（006 §2.4）。手番インデックスを進め、手番中だけの一時データを消す。
 */
class ExploreTurnEnd extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::EXPLORE_TURN_END,
            type: StateType::GAME,
            transitions: [
                'next' => StateId::EXPLORE_TURN_START,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $globals = $this->game->globalValues;
        $globals->setExploreIndex($globals->exploreIndex() + 1);
        $globals->setExploreState(null);

        return 'next';
    }
}
