<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 034 粉塵バキューマ：探査場のカードを 1 枚無料で獲得する。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-11 で実装する。
 */
class FreeAcquireChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::FREE_ACQUIRE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose a card to acquire for free'),
            descriptionMyTurn: clienttranslate('${you} must choose a card to acquire for free'),
            transitions: [
                'acquired' => StateId::ACQUIRE_DISPATCH,
            ],
        );
    }

    public function onEnteringState(): string
    {
        return 'acquired';
    }

    public function zombie(int $playerId): string
    {
        return 'acquired';
    }
}
