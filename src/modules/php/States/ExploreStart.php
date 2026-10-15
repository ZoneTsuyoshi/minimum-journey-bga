<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 探査出力の決定と探査手番の決定（006 §2.4）。
 * 骨格では探査出力を全員 0、手番を席順とする。I-06 で正しい計算・順序に置き換える。
 */
class ExploreStart extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::EXPLORE_START,
            type: StateType::GAME,
            transitions: [
                'start' => StateId::EXPLORE_TURN_START,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $seatOrder = $this->game->seatOrder();
        $order = RoundFlow::placeholderExploreOrder($seatOrder);
        $outputs = [];
        foreach ($seatOrder as $playerId) {
            $outputs[$playerId] = 0; // I-06: 011 §6 の手順で計算する
            $this->game->playerValues->setExploreOutput($playerId, 0);
        }

        $globals = $this->game->globalValues;
        $globals->setPhase(GlobalValues::PHASE_EXPLORE);
        $globals->setExploreOrder($order);
        $globals->setExploreIndex(0);

        $this->bga->notify->all('exploreStarted', clienttranslate('The exploration phase begins'), [
            'order' => $order,
            'outputs' => $outputs,
        ]);

        return 'start';
    }
}
