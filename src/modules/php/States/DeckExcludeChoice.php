<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 055 ブラックホール発生機：自分のデッキから最大 2 枚を除外する。
 * 骨格（I-04）では呼ばれても何もせず次へ進む差し込み口。中身は I-11 で実装する。
 */
class DeckExcludeChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::DECK_EXCLUDE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} may exclude up to 2 cards from their deck'),
            descriptionMyTurn: clienttranslate('${you} may exclude up to 2 cards from your deck'),
            transitions: [
                'done' => StateId::ACQUIRE_DISPATCH,
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
