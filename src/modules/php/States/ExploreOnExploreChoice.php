<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 探査時効果（011・013）の使用。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-11 で実装する。
 */
class ExploreOnExploreChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::EXPLORE_ON_EXPLORE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} may use on-explore effects'),
            descriptionMyTurn: clienttranslate('${you} may use on-explore effects'),
            transitions: [
                'again' => StateId::EXPLORE_ON_EXPLORE_CHOICE,
                'done' => StateId::EXPLORE_CHOOSE,
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
