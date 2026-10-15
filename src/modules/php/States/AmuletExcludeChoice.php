<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Data\BoardView;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 058 厄除けのお守り：新所有者が準備場の表向きのカード（横向きを含む）を最大 1 枚除外する（0 枚可）。
 * 帰還フェイズ②（シャッフル）の前に行う。
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

    /**
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        return [
            'candidates' => array_map(BoardView::cardView(...), $this->game->explorePhase->acquirePhase()->prepCandidates($activePlayerId)),
        ];
    }

    /**
     * cardId が 0 なら除外しない。
     */
    #[PossibleAction]
    public function actExcludePrepCard(int $cardId, int $activePlayerId): string
    {
        try {
            $result = $this->game->explorePhase->acquirePhase()->excludePrepOptional($activePlayerId, $cardId > 0 ? $cardId : null);
        } catch (ExploreRuleViolation $violation) {
            throw $this->game->mjExploreError($violation);
        }
        $this->game->giveExtraTime($activePlayerId);
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }

    /**
     * 離脱したプレイヤーは除外しない（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        return 'done';
    }
}
