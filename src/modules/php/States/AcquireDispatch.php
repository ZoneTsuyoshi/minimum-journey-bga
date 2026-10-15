<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 獲得時効果の振り分け（006 §2.4、008 §5.3）。
 * 獲得そのもの（獲得エリアへの移動と終了フラグ）は ExplorePhase::explore が済ませ、解決待ちの列に積んである。
 * 先頭から 1 枚ずつ解決し、選択が要る効果（004・034・055）は 45・46・47 へ出て、終わったらここへ戻る。
 * 全て解決し終えたら、探査フェイズでは追加探査（012）の判定を経て 48 へ。準備フェイズ（036 の入れ替え）では 30 へ戻る。
 */
class AcquireDispatch extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::ACQUIRE_DISPATCH,
            type: StateType::GAME,
            transitions: [
                'exclude' => StateId::ACQUIRE_EXCLUDE_CHOICE,
                'freeAcquire' => StateId::FREE_ACQUIRE_CHOICE,
                'blackHole' => StateId::DECK_EXCLUDE_CHOICE,
                'additional' => StateId::EXPLORE_CHOOSE,
                'done' => StateId::EXPLORE_TURN_END,
                'resume' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $result = $this->game->explorePhase->acquirePhase()->next();
        $this->game->sendNotifications($result->notifications);
        if ($result->transition !== 'finished') {
            $this->game->gamestate->changeActivePlayer((int) $result->playerId);
            return (string) $result->transition;
        }
        if ($this->game->globalValues->phase() !== GlobalValues::PHASE_EXPLORE) {
            return 'resume';
        }
        $state = $this->game->globalValues->exploreState();
        return $state === null ? 'done' : $this->game->explorePhase->afterAcquisitions($state['player_id']);
    }
}
