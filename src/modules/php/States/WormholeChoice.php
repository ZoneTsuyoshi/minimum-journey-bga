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
 * 036 簡易ワームホール（P4）：表にした山札の上のカードを、列の同じ探査コストのカードと入れ替えるか選ぶ。
 * 入れ替えなくても山札をシャッフルする（002 No.42 (e)）。
 */
class WormholeChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::WORMHOLE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose whether to swap a card'),
            descriptionMyTurn: clienttranslate('${you} must choose whether to swap a card'),
            transitions: [
                'done' => StateId::DRAW_RESOLVE,
                'acquire' => StateId::ACQUIRE_DISPATCH,
            ],
        );
    }

    /**
     * 表にしたカードは公開情報。
     *
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        $resolver = $this->game->drawResolver;
        $revealed = $resolver->wormholeRevealed();
        return [
            'revealedCard' => $revealed === null ? null : BoardView::cardView($revealed),
            'candidates' => $revealed === null ? [] : array_map(BoardView::cardView(...), $resolver->wormholeCandidates($revealed)),
            'ownerId' => $resolver->wormholeOwner(),
        ];
    }

    /**
     * 列のカードの ID。0 は入れ替えない。
     */
    #[PossibleAction]
    public function actWormhole(int $rowCardId, int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);
        return $this->swap($activePlayerId, $rowCardId > 0 ? $rowCardId : null);
    }

    /**
     * 離脱したプレイヤーは入れ替えない（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        return $this->swap($playerId, null);
    }

    private function swap(int $playerId, ?int $rowCardId): string
    {
        try {
            $step = $this->game->drawResolver->wormhole($playerId, $rowCardId);
        } catch (DrawRuleViolation $violation) {
            throw $this->game->drawError($violation);
        }
        $this->game->sendNotifications($step->notifications);

        // 山札に加わった列のカードの獲得時効果があれば 44 で解決する（008 N-1）。終われば 44 が 30 へ戻す
        return $this->game->explorePhase->acquirePhase()->hasPending() ? 'acquire' : 'done';
    }
}
