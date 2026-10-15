<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 049 時間停止装置：他プレイヤーの選択の開示後にドロー／ストップを選ぶ。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-05 で実装する。
 */
class PrepChoiceLate extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::PREP_CHOICE_LATE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose: draw or stop'),
            descriptionMyTurn: clienttranslate('${you} must choose: draw or stop'),
            transitions: [
                'chosen' => StateId::PREP_REVEAL,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'chosen';
    }

    public function zombie(int $playerId): string
    {
        return 'chosen';
    }
}
