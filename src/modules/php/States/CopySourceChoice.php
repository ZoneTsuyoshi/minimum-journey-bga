<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 008 のコピー元の選択。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-09 で実装する。
 */
class CopySourceChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::COPY_SOURCE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose a card to copy'),
            descriptionMyTurn: clienttranslate('${you} must choose a card to copy'),
            transitions: [
                'chosen' => StateId::DRAW_RESOLVE,
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
