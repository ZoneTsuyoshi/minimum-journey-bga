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
 * 008 観測衛星のコピー元の選択（P2a の直後、P3 の前。K1）。選んだカードのレベルが 008 の解決レベルになる（H5）。
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

    /**
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        $resolver = $this->game->drawResolver;
        return [
            'candidates' => array_map(BoardView::cardView(...), $resolver->copyCandidates()),
            'copyCardId' => $resolver->copyCardId(),
        ];
    }

    #[PossibleAction]
    public function actChooseCopySource(int $cardId, int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);
        try {
            $step = $this->game->drawResolver->chooseCopy($activePlayerId, $cardId);
        } catch (DrawRuleViolation $violation) {
            throw $this->game->drawError($violation);
        }
        $this->game->sendNotifications($step->notifications);

        return 'chosen';
    }

    /**
     * 離脱したプレイヤーは候補の最小 ID を選ぶ（強制選択。006 §7）。
     */
    public function zombie(int $playerId): string
    {
        $this->game->sendNotifications($this->game->drawResolver->zombieCopy($playerId)->notifications);

        return 'chosen';
    }
}
