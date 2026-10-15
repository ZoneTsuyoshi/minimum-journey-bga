<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 043 暴走転送ポッド：対象全員が同時・非公開に戻すかを決める。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-09 で実装する。
 */
class ReturnPodDecision extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_POD_DECISION,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Some players must decide whether to return their drawn card'),
            descriptionMyTurn: clienttranslate('${you} must decide whether to return your drawn card'),
            transitions: [
                'allDecided' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'allDecided';
    }

    /**
     * 離脱したプレイヤーは非ブロッキングにする（006 §7）。
     */
    public function zombie(int $playerId): void
    {
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allDecided');
    }
}
