<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PlayerValues;
use Bga\Games\MinimumJourney\Data\PrepChoice;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;

/**
 * 準備フェイズ（状態 20〜24。仕様書 006 §2.2・§4、001 §5）の DB を使う部分。
 * BGA の Table には依存せず、送る通知は戻り値で返す（状態クラスが送る）。結合テストで直接動かす。
 */
final class PrepPhase
{
    /** @var callable(int): string */
    private $playerName;
    private readonly ExploreOutputCalculator $calculator;

    /**
     * @param callable(int): string $playerName プレイヤー名の取得
     */
    public function __construct(
        private readonly CardRepository $cards,
        private readonly PlayerValues $players,
        private readonly GlobalValues $globals,
        callable $playerName,
        ?ExploreOutputCalculator $calculator = null,
    ) {
        $this->playerName = $playerName;
        $this->calculator = $calculator ?? new ExploreOutputCalculator($cards, $globals);
    }

    /**
     * 状態 20。記録（054・028・029・049）を読んで分類し、確定済みの選択を書き、記録を消費する。
     *
     * @param list<int> $seatOrder
     */
    public function begin(array $seatOrder): PrepStepResult
    {
        $plan = PrepClassifier::plan(
            $seatOrder,
            $this->players->prepFinishedMap(),
            $this->deckCounts($seatOrder),
            $this->globals->nextChoiceRecords(),
        );

        $this->players->clearPrepChoices();
        foreach ($plan->cancelled as $playerId) {
            $this->players->setPrepFinished($playerId, false);
        }
        foreach ($plan->fixed as $playerId => $choice) {
            $this->players->setPrepChoice($playerId, $choice);
        }
        $this->globals->setPrepSelectors($plan->needChoice);
        $this->globals->setPrepLatePlayer($plan->late);
        $this->globals->setPrepReasons($plan->reasons);
        $this->globals->setNextChoiceRecords(GlobalValues::emptyNextChoiceRecords());

        $notifications = [];
        if ($plan->cancelled !== []) {
            $names = implode(', ', array_map($this->playerName, $plan->cancelled));
            $notifications[] = new Notification(null, 'prepFinishedCleared', clienttranslate('Preparation is no longer finished for ${names}'), [
                'player_ids' => $plan->cancelled,
                'names' => $names,
            ]);
        }

        return new PrepStepResult($plan->transition(), $notifications);
    }

    /**
     * 状態 21 の選択（初回と変更）。確定の判定は永続データで行うので、ここでは記録だけする（006 §4.4）。
     *
     * @throws PrepRuleViolation
     */
    public function select(int $playerId, PrepChoice $choice): PrepStepResult
    {
        if (!in_array($playerId, $this->globals->prepSelectors(), true)) {
            throw new PrepRuleViolation(PrepRuleViolation::NOT_SELECTOR);
        }
        if ($choice === PrepChoice::Draw && $this->cards->count(Zone::Deck, $playerId) === 0) {
            throw new PrepRuleViolation(PrepRuleViolation::EMPTY_DECK);
        }

        $first = $this->players->prepChoice($playerId) === null;
        $this->players->setPrepChoice($playerId, $choice);

        // 内容は本人にだけ送る。公開通知は、選び終えた事実だけを最初の 1 回送る（007 N3・N4）
        $notifications = [new Notification($playerId, 'prepChoiceRecorded', '', ['choice' => $choice->value])];
        if ($first) {
            $notifications[] = new Notification(null, 'prepMarked', '', ['player_id' => $playerId]);
        }
        return new PrepStepResult(null, $notifications, $first);
    }

    /**
     * 状態 22。049 の後選びがいなければ 24 へ。いれば後選び以外の全員の選択を公開して 23 へ（006 §4.5、D9）。
     *
     * @param list<int> $seatOrder
     */
    public function lock(array $seatOrder): PrepStepResult
    {
        $late = $this->globals->prepLatePlayer();
        if ($late === null) {
            return new PrepStepResult('reveal', []);
        }
        return new PrepStepResult('lateChoice', $this->choiceNotifications($this->participants($seatOrder)));
    }

    /**
     * 状態 23 の後選び。押した時点で確定。
     *
     * @throws PrepRuleViolation
     */
    public function selectLate(int $playerId, PrepChoice $choice): PrepStepResult
    {
        if ($playerId !== $this->globals->prepLatePlayer()) {
            throw new PrepRuleViolation(PrepRuleViolation::NOT_LATE_PLAYER);
        }
        if ($choice === PrepChoice::Draw && $this->cards->count(Zone::Deck, $playerId) === 0) {
            throw new PrepRuleViolation(PrepRuleViolation::EMPTY_DECK);
        }
        $this->players->setPrepChoice($playerId, $choice);
        return new PrepStepResult('chosen', []);
    }

    /**
     * 状態 24。選択を開示し、ドローしたカードを準備場へ、ストップした人を準備終了にし、分岐する。
     * ドロー 1 人以上の回はストップした人が山札の上から裏向きで 1 枚を別置き場へ引く。1 人だけのドローは横向き・半減。
     *
     * @param list<int> $seatOrder
     */
    public function reveal(array $seatOrder): PrepStepResult
    {
        $choices = $this->choices($seatOrder);
        $plan = PrepClassifier::reveal($seatOrder, $choices, $this->deckCounts($seatOrder));

        // 049 の後選びがいたときは、他の人の分を 22 で公開済み。その人の分だけ送る
        $late = $this->globals->prepLatePlayer();
        $toReveal = $late === null ? $this->participants($seatOrder) : [$late];
        $notifications = $this->choiceNotifications($toReveal);
        $this->globals->setPrepLatePlayer(null);

        $drawn = [];
        foreach ($plan->drawers as $playerId) {
            $card = $this->cards->drawTopToPrep($playerId);
            if ($card === null) {
                continue; // 山札があることは plan で確認済み
            }
            $drawn[$playerId] = $card;
            $notifications[] = new Notification(null, 'cardDrawn', clienttranslate('${player_name} draws ${card_name}'), [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card' => self::cardView($card),
                'card_name' => self::cardName($card),
                'card_no' => $card->cardNo,
                'order' => $card->order,
                'reason' => 'draw',
                'source_name' => '',
                'i18n' => ['card_name'],
            ]);
        }

        foreach ($plan->stoppers as $playerId) {
            $this->players->setPrepFinished($playerId, true);
            if ($plan->stoppersDrawFacedown && $this->cards->drawTopToFacedown($playerId) !== null) {
                // 引いたカードの情報は誰にも送らない（007 N7）
                $notifications[] = new Notification(null, 'faceDownSet', clienttranslate('${player_name} sets aside a face-down card'), [
                    'player_id' => $playerId,
                    'player_name' => ($this->playerName)($playerId),
                ]);
            }
        }

        if ($plan->transition === 'soloDraw' && count($drawn) === 1) {
            $playerId = (int) array_key_first($drawn);
            $card = $drawn[$playerId];
            $this->cards->setSideways($card, true);
            $this->players->setHalved($playerId, true);
            $notifications[] = new Notification(null, 'cardSideways', clienttranslate('${card_name} is turned sideways: its power and effects are ignored'), [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card_id' => $card->id,
                'card_name' => self::cardName($card),
                'card_no' => $card->cardNo,
                'i18n' => ['card_name'],
            ]);
        }

        $notifications[] = $this->counters($plan->drawers, $plan->stoppers);

        return new PrepStepResult($plan->transition, $notifications);
    }

    /**
     * 選択者（この回に選択が入ったプレイヤー）。席順。
     *
     * @param list<int> $seatOrder
     * @return list<int>
     */
    private function participants(array $seatOrder): array
    {
        return array_values(array_filter(
            $seatOrder,
            fn(int $playerId): bool => $this->players->prepChoice($playerId) !== null,
        ));
    }

    /**
     * @param list<int> $seatOrder
     * @return array<int, PrepChoice|null>
     */
    private function choices(array $seatOrder): array
    {
        $choices = [];
        foreach ($seatOrder as $playerId) {
            $choices[$playerId] = $this->players->prepChoice($playerId);
        }
        return $choices;
    }

    /**
     * @param list<int> $seatOrder
     * @return array<int, int>
     */
    private function deckCounts(array $seatOrder): array
    {
        $counts = [];
        foreach ($seatOrder as $playerId) {
            $counts[$playerId] = $this->cards->count(Zone::Deck, $playerId);
        }
        return $counts;
    }

    /**
     * N2 choiceRevealed（選択者ごと 1 件）。
     *
     * @param list<int> $playerIds
     * @return list<Notification>
     */
    private function choiceNotifications(array $playerIds): array
    {
        $reasons = $this->globals->prepReasons();
        $result = [];
        foreach ($playerIds as $playerId) {
            $choice = $this->players->prepChoice($playerId);
            if ($choice === null) {
                continue;
            }
            $result[] = new Notification(null, 'choiceRevealed', clienttranslate('${player_name} chooses ${choice_label}'), [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'choice' => $choice->value,
                'choice_label' => $choice === PrepChoice::Draw ? clienttranslate('Draw') : clienttranslate('Stop'),
                'reason' => $reasons[$playerId] ?? null,
                'i18n' => ['choice_label'],
            ]);
        }
        return $result;
    }

    /**
     * N33 countersUpdate（変更があったプレイヤーの枚数と、現在の探査出力 outputNow。お守りを含み半減は含めない）。
     *
     * @param list<int> $drawers
     * @param list<int> $stoppers
     */
    private function counters(array $drawers, array $stoppers): Notification
    {
        $players = [];
        foreach ([...$drawers, ...$stoppers] as $playerId) {
            $players[$playerId] = [
                'deckCount' => $this->cards->count(Zone::Deck, $playerId),
                'facedownCount' => $this->cards->count(Zone::Facedown, $playerId),
                'prepCount' => $this->cards->count(Zone::Prep, $playerId),
                'outputNow' => $this->calculator->outputNow($playerId),
            ];
        }
        return new Notification(null, 'countersUpdate', '', ['players' => $players]);
    }

    /**
     * @return array{id: int, cardNo: int, art: int}
     */
    private static function cardView(Card $card): array
    {
        return ['id' => $card->id, 'cardNo' => $card->cardNo, 'art' => $card->art];
    }

    private static function cardName(Card $card): string
    {
        return CardDefinitions::get(sprintf('%03d', $card->cardNo))->nameEn ?? '';
    }
}
