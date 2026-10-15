<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * ドロー時効果の解決エンジン（010 §6）。骨格ではドロー時効果が起きないので、何もせず次の準備選択へ進む。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-09 で実装する。
 */
class DrawResolve extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::DRAW_RESOLVE,
            type: StateType::GAME,
            transitions: [
                'pod' => StateId::RETURN_POD_DECISION,
                'copy' => StateId::COPY_SOURCE_CHOICE,
                'wormhole' => StateId::WORMHOLE_CHOICE,
                'laser' => StateId::LASER_SIGHT_CHOICE,
                'simul' => StateId::SIMULTANEOUS_EFFECTS,
                'done' => StateId::PREP_STEP_START,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'done';
    }
}
