<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 058 厄除けのお守り：新所有者が準備場のカードを最大 1 枚除外する。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-11 で実装する。
 */
class AmuletExcludeChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::AMULET_EXCLUDE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} may exclude a card from their prep area'),
            descriptionMyTurn: clienttranslate('${you} may exclude a card from your prep area'),
            transitions: [
                'done' => StateId::RETURN_SHUFFLE,
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
