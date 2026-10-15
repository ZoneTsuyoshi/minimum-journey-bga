<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 選択の開示と分岐（006 §2.2、001 §5 ②③）。
 * ドローした人は山札の上から準備場へ、ストップした人は準備終了（ドローがあった回は裏向きで別置き場へ 1 枚）。
 * 全員ストップ・1 人だけドロー（横向き・半減）は探査へ、2 人以上ドローは解決（30）へ。解決の中身は I-09。
 */
class PrepReveal extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::PREP_REVEAL,
            type: StateType::GAME,
            transitions: [
                'allStop' => StateId::EXPLORE_START,
                'soloDraw' => StateId::EXPLORE_START,
                'resolve' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $result = $this->game->prepPhase->reveal($this->game->seatOrder());
        $this->game->sendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
