<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 自分にしか影響しない選択付きのドロー時効果を、該当者が同時に解決する。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-09 で実装する。
 */
class SimultaneousEffects extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::SIMULTANEOUS_EFFECTS,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Some players must resolve their effects'),
            descriptionMyTurn: clienttranslate('${you} must resolve your effect'),
            transitions: [
                'allDone' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'allDone';
    }

    /**
     * 離脱したプレイヤーは非ブロッキングにする（006 §7）。
     */
    public function zombie(int $playerId): void
    {
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allDone');
    }
}
