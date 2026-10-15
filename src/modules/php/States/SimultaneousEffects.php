<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\Actions\Types\StringParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Data\BoardView;
use Bga\Games\MinimumJourney\Flow\DrawEffects;
use Bga\Games\MinimumJourney\Flow\DrawRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 自分にしか影響しない選択付きのドロー時効果（P5-同時。005・009・010・014・020・035・050・052、008 のコピー）を、
 * 該当者が同時に解決する（D5。案 Y）。効果の種類はプレイヤーごとの _private の effect で区別する。
 * 見た山札のカードと候補は本人だけに返す（006 §9）。
 */
class SimultaneousEffects extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::SIMULTANEOUS_EFFECTS,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Some players must resolve their effects'),
            descriptionMyTurn: clienttranslate('${you} must resolve your effect'),
            transitions: [
                'allDone' => StateId::DRAW_RESOLVE,
            ],
        );
    }

    public function onEnteringState(): void
    {
        $pending = array_keys($this->game->drawResolver->simulRequests());
        $this->game->gamestate->setPlayersMultiactive($pending, 'allDone', true);
    }

    /**
     * 公開部分は未完了の者と効果カードだけ（何をするかは効果カードから分かる）。山札の中身は _private。
     * DB は書かない（006 B5）。
     *
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        $pending = [];
        $private = [];
        foreach ($this->game->drawResolver->simulRequests() as $playerId => $request) {
            $pending[] = [
                'playerId' => $playerId,
                'effectCardId' => $request['anchor']->id,
                'effectCardNo' => $request['entry']->effectNo(),
            ];
            $plan = $request['plan'];
            $private[$playerId] = [
                'effect' => $plan['type'],
                'seenCards' => array_map(BoardView::cardView(...), $plan['seen']),
                'candidates' => array_map(BoardView::cardView(...), $plan['candidates']),
                'prevCard' => $plan['prev'] === null ? null : BoardView::cardView($plan['prev']),
                'effectCardNo' => $request['entry']->effectNo(),
                'effectName' => DrawEffects::nameOf($request['entry']->effectNo()),
            ];
        }

        return ['pending' => $pending, '_private' => $private];
    }

    /** 005: 見た 2 枚を戻す順（先に渡した方が一番上）。 */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actArrangeTop(int $firstId, int $secondId): void
    {
        $this->mjAct('actArrangeTop', [$firstId, $secondId]);
    }

    /** 009・014: 戻す／戻さない。 */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actYesNo(bool $yes): void
    {
        $this->mjAct('actYesNo', $yes);
    }

    /** 010: 見たカードを山札の上に戻すか、下に置くか。 */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actTopOrBottom(#[StringParam(enum: ['top', 'bottom'])] string $position): void
    {
        $this->mjAct('actTopOrBottom', $position);
    }

    /** 020・035・050: 山札から選んで一番上に置く。0 は置かない（それでもシャッフルする）。 */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actPickFromDeck(int $cardId): void
    {
        $this->mjAct('actPickFromDeck', $cardId > 0 ? $cardId : null);
    }

    /** 052: 見た 2 枚から山札の上に戻す 1 枚（残りは山札の下）。 */
    #[PossibleAction]
    #[CheckAction(false)]
    public function actPickOne(int $cardId): void
    {
        $this->mjAct('actPickOne', $cardId);
    }

    /**
     * 離脱したプレイヤーは山札・準備場を変えない選択をする（006 §7）。
     */
    public function zombie(int $playerId): void
    {
        if (isset($this->game->drawResolver->simulRequests()[$playerId])) {
            $this->game->mjSendNotifications($this->game->drawResolver->simulZombie($playerId)->notifications);
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allDone');
    }

    private function mjAct(string $action, mixed $payload): void
    {
        $playerId = (int) $this->game->getCurrentPlayerId();
        try {
            $step = $this->game->drawResolver->simulAction($playerId, $action, $payload);
        } catch (DrawRuleViolation $violation) {
            throw $this->game->mjDrawError($violation);
        }
        $this->game->giveExtraTime($playerId);
        $this->game->mjSendNotifications($step->notifications);
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'allDone');
    }
}
