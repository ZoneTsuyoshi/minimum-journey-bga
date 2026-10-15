<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 獲得時効果の振り分け。骨格では獲得が起きない（探査はパスのみ）ので、何もせず手番の終了へ進む。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-06 で実装する。
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
