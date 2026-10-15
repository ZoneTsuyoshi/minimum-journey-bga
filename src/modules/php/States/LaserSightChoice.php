<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Data\BoardView;
use Bga\Games\MinimumJourney\Flow\DrawRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 007 レーザー照準器（P5-順番）：探査場の列のカードを 1 枚選ぶ。このラウンド中、自分以外はそのカードを獲得できない。
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

    /**
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        $resolver = $this->game->drawResolver;
        return [
            'candidates' => array_map(BoardView::cardView(...), $resolver->laserCandidates()),
            'ownerId' => $resolver->laserOwner(),
        ];
    }

    #[PossibleAction]
    public function actChooseLockedCard(int $cardId, int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);
        try {
            $step = $this->game->drawResolver->chooseLockedCard($activePlayerId, $cardId);
        } catch (DrawRuleViolation $violation) {
            throw $this->game->mjDrawError($violation);
        }
        $this->game->mjSendNotifications($step->notifications);

        return 'done';
    }

    /**
     * 離脱したプレイヤーは対象を選ばない（ロックしない。006 §7）。
     */
    public function zombie(int $playerId): string
    {
        $this->game->mjSendNotifications($this->game->drawResolver->zombieLaser($playerId)->notifications);

        return 'done';
    }
}
