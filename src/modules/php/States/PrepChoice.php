<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\MinimumJourney\Data\PrepChoice as Choice;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\PrepRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * ドロー／ストップの同時・秘密選択（006 §2.2、§4 の案 B）。
 * 選び終えた人は非アクティブになるが、全員が選び終えるまで選択を変更できる。
 * 最後の 1 人が初めて選ぶとフレームワークが allChosen で遷移する。
 */
class PrepChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::PREP_CHOICE,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Other players must choose: draw or stop'),
            descriptionMyTurn: clienttranslate('${you} must choose: draw or stop'),
            transitions: [
                'allChosen' => StateId::PREP_LOCK,
            ],
        );
    }

    public function onEnteringState(): void
    {
        $this->game->gamestate->setPlayersMultiactive($this->game->globalValues->prepSelectors(), 'allChosen', true);
    }

    /**
     * 公開部分は選択内容を含めない（006 §4.3、§9.2）。本人の選択は _private で返す。DB は書かない。
     * forced は自動で確定した人とその理由（028・029・054・山札 0 枚。公開情報）。
     *
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        $values = $this->game->playerValues;
        $selectors = $this->game->globalValues->prepSelectors();
        $chosenMap = $values->prepChosenMap();

        $private = [];
        foreach ($selectors as $playerId) {
            $choice = $values->prepChoice($playerId);
            $private[$playerId] = [
                'myChoice' => $choice?->value,
                'canDraw' => $this->game->cards->count(Zone::Deck, $playerId) > 0,
            ];
        }

        $forced = [];
        foreach ($this->game->globalValues->prepReasons() as $playerId => $reason) {
            $forced[] = ['player_id' => $playerId, 'reason' => $reason];
        }

        return [
            'selectors' => $selectors,
            'chosen' => array_values(array_filter($selectors, static fn(int $id): bool => $chosenMap[$id] ?? false)),
            'forced' => $forced,
            '_private' => $private,
        ];
    }

    /**
     * 選択（'draw' / 'stop'）。全員が選び終えるまで変更できる。
     * 選択済みの人は非アクティブなので、フレームワークのアクティブ検査は外し、選択者かどうかを自前で検証する。
     */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actSelectPrep(string $choice): void
    {
        $selected = Choice::tryFrom($choice);
        if ($selected === null) {
            throw new UserException($this->game->_('Invalid choice'));
        }
        $this->mjRecord((int) $this->game->getCurrentPlayerId(), $selected);
    }

    /**
     * 離脱したプレイヤーは未選択ならストップにして、非ブロッキングにする（006 §7）。
     */
    public function zombie(int $playerId): void
    {
        if (!in_array($playerId, $this->game->globalValues->prepSelectors(), true)) {
            return;
        }
        if ($this->game->playerValues->prepChoice($playerId) === null) {
            $this->mjRecord($playerId, Choice::Stop);
            return;
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allChosen');
    }

    private function mjRecord(int $playerId, Choice $choice): void
    {
        try {
            $result = $this->game->prepPhase->select($playerId, $choice);
        } catch (PrepRuleViolation $violation) {
            throw $this->game->mjPrepError($violation);
        }
        $this->game->mjSendNotifications($result->notifications);
        if ($result->firstChoice) {
            $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allChosen');
        }
    }
}
