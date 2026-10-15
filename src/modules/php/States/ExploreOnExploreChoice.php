<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 探査時効果（011・013）の使用（006 §2.4、008 §4.5）。手番の開始時に、選んだ順に 1 つずつ使う（任意）。
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

    /**
     * 使える効果がなければそのまま探査へ進む。
     */
    public function onEnteringState(int $activePlayerId): ?string
    {
        return $this->game->explorePhase->hasUsableOnExplore($activePlayerId) ? null : 'done';
    }

    /**
     * 使える効果のカード ID と、011 の選べる山札のコスト（公開情報）。
     *
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        return ['usable' => $this->game->explorePhase->usableOnExplore($activePlayerId)];
    }

    /**
     * 011 は deckCost = 加える山札のコスト（2/4/6/8）、013 は 0。
     */
    #[PossibleAction]
    public function actUseOnExplore(int $cardId, int $deckCost, int $activePlayerId): string
    {
        try {
            $result = $this->game->explorePhase->useOnExplore($activePlayerId, $cardId, $deckCost > 0 ? $deckCost : null);
        } catch (ExploreRuleViolation $violation) {
            throw $this->game->mjExploreError($violation);
        }
        $this->game->giveExtraTime($activePlayerId);
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }

    #[PossibleAction]
    public function actSkipOnExplore(int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);
        return 'done';
    }

    /**
     * 離脱したプレイヤーは何も使わない（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        return 'done';
    }
}
