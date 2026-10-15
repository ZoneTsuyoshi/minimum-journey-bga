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
 * 探査対象の選択（006 §2.4、§6.1）。探査できる対象がなければ入場時に自動パスする。
 * 確認ステップ（選ぶ → [Explore]、[Cancel]）はクライアント状態で行い、サーバーには確定時の actExplore だけが届く。
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
     * 探査できる対象がなければ自動パス（009 §9.2、ログに出す）。
     */
    public function onEnteringState(int $activePlayerId): ?string
    {
        $result = $this->game->explorePhase->enterChoose($activePlayerId);
        $this->game->sendNotifications($result->notifications);

        return $result->transition;
    }

    /**
     * 探査できる対象と実効コストを送る（山札の上はコストと枚数だけで、カードの ID や券面は送らない。007 §9.3）。
     *
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        $targets = $this->game->explorePhase->targets($activePlayerId);
        $state = $this->game->globalValues->exploreState();

        return [
            'output' => $this->game->playerValues->exploreOutput($activePlayerId) ?? 0,
            'rowTargets' => $targets['rowTargets'],
            'unknownTarget' => $targets['unknownTarget'],
            'deckTargets' => $targets['deckTargets'],
            'additional' => $state['additional_done'] ?? false,
            'exploredCostSum' => $state['sum_cost'] ?? 0,
            'canPass' => true,
            'lockedCard' => $this->game->globalValues->lockedCard(),
        ];
    }

    /**
     * 探査する。source は row（列の表向きのカード）／unknown（未確認帯域）／deck（探査山札の上）。
     * row・unknown は cardId、deck は cost を使う（使わない方は 0）。
     */
    #[PossibleAction]
    public function actExplore(string $source, int $cardId, int $cost, int $activePlayerId): string
    {
        try {
            $result = $this->game->explorePhase->explore($activePlayerId, $source, $cardId, $cost);
        } catch (ExploreRuleViolation $violation) {
            throw $this->game->exploreError($violation);
        }
        $this->game->giveExtraTime($activePlayerId);
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
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
        $result = $this->game->explorePhase->pass($playerId);
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
