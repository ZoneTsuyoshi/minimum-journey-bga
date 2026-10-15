<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 帰還フェイズ③④：列の補充と終了判定（006 §2.5）。
 * 骨格では補充せず、規定ラウンド数（RoundFlow::PLACEHOLDER_ROUND_LIMIT）で終わる仮実装。I-07 で正しい判定に置き換える。
 */
class ReturnRefill extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_REFILL,
            type: StateType::GAME,
            updateGameProgression: true,
            transitions: [
                'nextRound' => StateId::ROUND_START,
                'end' => StateId::FINAL_SCORING,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $this->refillRows();

        return RoundFlow::isGameOver($this->game->globalValues->roundNo()) ? 'end' : 'nextRound';
    }

    /**
     * I-07: 探査場の列を規定枚数まで補充し、コスト 8 の探査山札が尽きたら終了フラグを立てる。
     */
    protected function refillRows(): void
    {
    }
}
