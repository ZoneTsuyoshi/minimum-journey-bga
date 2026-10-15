<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Flow\DrawEffects;
use Bga\Games\MinimumJourney\Flow\DrawRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 043 暴走転送ポッド（P2）：対象全員（持ち主以外でこの回にカードを引いた人）が、引いたカードを山札に戻すかを
 * 同時に・互いに見えない状態で決める（H8）。押した時点で確定する。結果は全員が決めた後に 30 が適用して公開する。
 */
class ReturnPodDecision extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_POD_DECISION,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Some players must decide whether to return their drawn card'),
            descriptionMyTurn: clienttranslate('${you} must decide whether to return your drawn card'),
            transitions: [
                'allDecided' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): void
    {
        $this->game->gamestate->setPlayersMultiactive($this->game->drawResolver->podTargetPlayers(), 'allDecided', true);
    }

    /**
     * 公開部分は決定済みの人だけで、内容は含めない（H8）。本人の決定と引いたカードは _private。
     *
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        $resolver = $this->game->drawResolver;
        $targets = $resolver->podTargetPlayers();
        $private = [];
        foreach ($targets as $playerId) {
            $card = $resolver->drawnCardOf($playerId);
            $private[$playerId] = [
                'myCard' => $card === null ? null : DrawEffects::cardView($card),
                'myDecision' => $resolver->podDecisionOf($playerId),
            ];
        }

        return [
            'targets' => $targets,
            'decided' => $resolver->podDecidedPlayers(),
            'podOwner' => $resolver->podOwner(),
            '_private' => $private,
        ];
    }

    /**
     * 戻す（true）／戻さない（false）。決めた人は非アクティブなので、アクティブ検査は外し、対象者かどうかを自前で検証する。
     */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actDecideReturn(bool $doReturn): void
    {
        $this->mjDecide((int) $this->game->getCurrentPlayerId(), $doReturn);
    }

    /**
     * 離脱したプレイヤーは戻さない（006 §7）。
     */
    public function zombie(int $playerId): void
    {
        if ($this->game->drawResolver->podDecisionOf($playerId) === null) {
            $this->mjDecide($playerId, false);
            return;
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allDecided');
    }

    private function mjDecide(int $playerId, bool $doReturn): void
    {
        try {
            $step = $this->game->drawResolver->decideReturn($playerId, $doReturn);
        } catch (DrawRuleViolation $violation) {
            throw $this->game->mjDrawError($violation);
        }
        $this->game->mjSendNotifications($step->notifications);
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allDecided');
    }
}
