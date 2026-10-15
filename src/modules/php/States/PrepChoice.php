<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\MinimumJourney\Data\PrepChoice as Choice;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * ドロー／ストップの同時・秘密選択（006 §2.2、§4 の案 B）。
 * 骨格ではストップだけ機能する（ドローは I-05）。選び終えた人は非アクティブになり、
 * 最後の 1 人が選ぶとフレームワークが allChosen で遷移する。
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
     * 公開部分は選択内容を含めない（006 §4.3）。本人の選択は _private で返す。DB は書かない。
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
                'canDraw' => false, // I-05 で山札の枚数から判定する
            ];
        }

        return [
            'selectors' => $selectors,
            'chosen' => array_values(array_filter($selectors, static fn(int $id): bool => $chosenMap[$id] ?? false)),
            '_private' => $private,
        ];
    }

    /**
     * 選択（'draw' / 'stop'）。全員が選び終えるまで変更できるが、骨格ではストップしか選べない。
     * 選択済みの人は非アクティブなので、フレームワークのアクティブ検査は外し、選択者かどうかを自前で検証する。
     */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actSelectPrep(string $choice): void
    {
        $playerId = (int) $this->game->getCurrentPlayerId();
        $selected = Choice::tryFrom($choice);
        if ($selected === null) {
            throw new UserException($this->game->_('Invalid choice'));
        }
        if (!in_array($playerId, $this->game->globalValues->prepSelectors(), true)) {
            throw new UserException($this->game->_('You cannot choose now'));
        }
        if ($selected === Choice::Draw) {
            throw new UserException($this->game->_('Drawing is not available yet'));
        }
        $this->record($playerId, $selected);
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
            $this->record($playerId, Choice::Stop);
            return;
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allChosen');
    }

    private function record(int $playerId, Choice $choice): void
    {
        $values = $this->game->playerValues;
        $first = $values->prepChoice($playerId) === null;
        $values->setPrepChoice($playerId, $choice);

        // 内容は本人にだけ送る。公開通知には選択済みの事実だけ（006 §9.2）
        $this->bga->notify->player($playerId, 'prepChoiceRecorded', '', ['choice' => $choice->value]);
        if ($first) {
            $this->bga->notify->all('prepMarked', '', ['player_id' => $playerId]);
            $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allChosen');
        }
    }
}
