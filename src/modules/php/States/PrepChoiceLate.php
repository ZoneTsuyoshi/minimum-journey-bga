<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\Actions\Types\StringParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\PrepRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 049 時間停止装置：他プレイヤーの選択の開示後にドロー／ストップを選ぶ（006 §4.5、D9）。
 * 押した時点で確定する。アクティブプレイヤーは 22 が設定する。
 */
class PrepChoiceLate extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::PREP_CHOICE_LATE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose: draw or stop'),
            descriptionMyTurn: clienttranslate('${you} must choose: draw or stop'),
            transitions: [
                'chosen' => StateId::PREP_REVEAL,
            ],
        );
    }

    /**
     * 公開部分は、すでに公開済みの他プレイヤーの選択と、ドローできるか。
     *
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        $revealed = [];
        foreach ($this->game->seatOrder() as $playerId) {
            $choice = $this->game->playerValues->prepChoice($playerId);
            if ($playerId !== $activePlayerId && $choice !== null) {
                $revealed[$playerId] = $choice->value;
            }
        }
        return [
            'revealedChoices' => $revealed,
            'canDraw' => $this->game->cards->count(Zone::Deck, $activePlayerId) > 0,
        ];
    }

    #[PossibleAction]
    public function actSelectPrepLate(#[StringParam(enum: ['draw', 'stop'])] string $choice, int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);
        return $this->choose($activePlayerId, PrepChoice::from($choice));
    }

    /**
     * 離脱したプレイヤーはストップ（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        return $this->choose($playerId, PrepChoice::Stop);
    }

    private function choose(int $playerId, PrepChoice $choice): string
    {
        try {
            $result = $this->game->prepPhase->selectLate($playerId, $choice);
        } catch (PrepRuleViolation $violation) {
            throw $this->game->prepError($violation);
        }
        $this->game->sendNotifications($result->notifications);
        return (string) $result->transition;
    }
}
