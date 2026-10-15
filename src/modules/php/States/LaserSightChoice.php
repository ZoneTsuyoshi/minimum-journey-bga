<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 007 の探査場のカードの選択。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-09 で実装する。
 */
class LaserSightChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::LASER_SIGHT_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose a card to lock'),
            descriptionMyTurn: clienttranslate('${you} must choose a card to lock'),
            transitions: [
                'done' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'done';
    }

    public function zombie(int $playerId): string
    {
        return 'done';
    }
}
