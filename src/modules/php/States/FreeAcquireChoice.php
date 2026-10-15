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
 * 034 粉塵バキューマ：探査場の [太陽系] または [天の川銀河] のみを持つカードを 1 枚、無料で獲得する（必須）。
 * 候補が 0 枚なら 44 が状態に入れない。
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

    /**
     * 列の候補と、候補にできる山札のコスト。山札の上の中身は送らない。
     *
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        $candidates = $this->game->explorePhase->acquirePhase()->freeCandidates($activePlayerId);

        return [
            'rowCandidates' => array_map(BoardView::cardView(...), $candidates['rows']),
            'deckCandidates' => $candidates['decks'],
        ];
    }

    /**
     * type は row（ref = カード ID）か deck（ref = 山札のコスト）。
     */
    #[PossibleAction]
    public function actFreeAcquire(string $type, int $ref, int $activePlayerId): string
    {
        try {
            $result = $this->game->explorePhase->acquirePhase()->freeAcquire($activePlayerId, $type, $ref);
        } catch (ExploreRuleViolation $violation) {
            throw $this->game->mjExploreError($violation);
        }
        $this->game->giveExtraTime($activePlayerId);
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }

    /**
     * 離脱したプレイヤーは名声が最大の列のカードを獲得する（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        $result = $this->game->explorePhase->acquirePhase()->zombieFreeAcquire($playerId);
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
