<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 帰還フェイズ①：お守りの移動（006 §2.5）。骨格では移動しない差し込み口（I-07）。
 */
class ReturnAmulet extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::RETURN_AMULET,
            type: StateType::GAME,
            transitions: [
                'exclude' => StateId::AMULET_EXCLUDE_CHOICE,
                'next' => StateId::RETURN_SHUFFLE,
            ],
        );
    }

    public function onEnteringState(): string
    {
        $this->game->globalValues->setPhase(GlobalValues::PHASE_RETURN);
        $this->moveAmulet();

        return 'next';
    }

    /**
     * I-07: 探査出力が最小のプレイヤー（同点は探査手番が遅い方）へお守りを移す。
     */
    protected function moveAmulet(): void
    {
    }
}
