<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 獲得時効果の振り分け。
 * 獲得そのもの（獲得エリアへの移動と終了フラグ）は I-06 の ExplorePhase::explore が済ませている。
 * 獲得時効果（004・034・055・040）は I-11 で実装する差し込み口で、いまは何もせず手番の終了へ進む。
 */
class AcquireDispatch extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::ACQUIRE_DISPATCH,
            type: StateType::GAME,
            transitions: [
                'exclude' => StateId::ACQUIRE_EXCLUDE_CHOICE,
                'freeAcquire' => StateId::FREE_ACQUIRE_CHOICE,
                'blackHole' => StateId::DECK_EXCLUDE_CHOICE,
                'additional' => StateId::EXPLORE_CHOOSE,
                'done' => StateId::EXPLORE_TURN_END,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'done';
    }
}
