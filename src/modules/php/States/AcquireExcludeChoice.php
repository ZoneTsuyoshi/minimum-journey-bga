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
 * 004 コロニーキャンプ：準備場の表向きのカード（横向きを含む）を 1 枚除外する（必須）。
 * 候補が 0 枚なら 44 が状態に入れない。
 */
class AcquireExcludeChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::ACQUIRE_EXCLUDE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must exclude a card from their prep area'),
            descriptionMyTurn: clienttranslate('${you} must exclude a card from your prep area'),
            transitions: [
                'done' => StateId::ACQUIRE_DISPATCH,
            ],
        );
    }

    /**
     * 準備場は公開情報。046 は本体のみ（付加カードは別の候補にしない）。
     *
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        return [
            'candidates' => array_map(BoardView::cardView(...), $this->game->explorePhase->acquirePhase()->prepCandidates($activePlayerId)),
        ];
    }

    #[PossibleAction]
    public function actExcludePrepCard(int $cardId, int $activePlayerId): string
    {
        try {
            $result = $this->game->explorePhase->acquirePhase()->excludePrepRequired($activePlayerId, $cardId);
        } catch (ExploreRuleViolation $violation) {
            throw $this->game->exploreError($violation);
        }
        $this->game->giveExtraTime($activePlayerId);
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
    }

    /**
     * 離脱したプレイヤーは名声が最小のカードを除外する（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        $result = $this->game->explorePhase->acquirePhase()->zombieExcludeRequired($playerId);
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
