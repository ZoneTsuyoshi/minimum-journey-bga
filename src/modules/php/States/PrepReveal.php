<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 選択の開示と分岐（006 §2.2）。ストップした人を準備終了にし、ドロー人数で分岐する。
 * 骨格ではドローが起きないので、常に allStop で探査へ進む。カードを引く処理は I-05。
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
        $values = $this->game->playerValues;
        $choices = [];
        foreach ($this->game->globalValues->prepSelectors() as $playerId) {
            $choices[$playerId] = $values->prepChoice($playerId);
        }

        foreach ($choices as $playerId => $choice) {
            if ($choice === PrepChoice::Stop) {
                $values->setPrepFinished($playerId, true);
            }
        }

        $this->bga->notify->all('prepRevealed', clienttranslate('Preparation choices are revealed'), [
            'choices' => array_map(static fn(?PrepChoice $c): ?string => $c?->value, $choices),
        ]);

        return RoundFlow::revealTransition($choices);
    }
}
