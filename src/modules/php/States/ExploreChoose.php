<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 探査対象の選択（006 §2.4）。骨格ではパスだけ受け付ける（探査・獲得は I-06）。
 */
class ExploreChoose extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::EXPLORE_CHOOSE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must explore or pass'),
            descriptionMyTurn: clienttranslate('${you} must explore or pass'),
            transitions: [
                'acquired' => StateId::ACQUIRE_DISPATCH,
                'passed' => StateId::EXPLORE_TURN_END,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        return [
            'output' => $this->game->playerValues->exploreOutput($activePlayerId) ?? 0,
            'additional' => false,
            'canPass' => true,
        ];
    }

    #[PossibleAction]
    public function actPass(int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);
        return $this->pass($activePlayerId);
    }

    /**
     * 離脱したプレイヤーはパスする（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        return $this->pass($playerId);
    }

    private function pass(int $playerId): string
    {
        $this->bga->notify->all('explorePassed', clienttranslate('${player_name} passes'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
        ]);
        return 'passed';
    }
}
