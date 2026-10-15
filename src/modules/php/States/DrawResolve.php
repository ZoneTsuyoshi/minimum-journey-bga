<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * ドロー時効果の解決エンジン（010 §6.1、006 §5.2）。resolve_level と resolve_entry で、優先度の順（P0 → P1 → P2 → P2a →
 * 008 のコピー元 → P3 → P4 → P5-順番 → P5-同時 → P6）に進める。自動で解決できるレベルは状態を出ずに実行し、
 * プレイヤーの選択が要るレベルだけ割り込み状態（31〜35）へ出る。割り込み状態は終わるとここに戻る。
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
        $step = $this->game->drawResolver->advance();
        if ($step->activePlayer !== null) {
            $this->game->gamestate->changeActivePlayer($step->activePlayer);
        }
        $this->game->sendNotifications($step->notifications);

        return (string) $step->transition;
    }
}
