<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Stats;

use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Flow\Notification;

/**
 * 統計の更新規則（007 §12）。BGA に依存しない。通知の内容から更新を導き、StatStore に書く。
 * 統計は通知を送る側の処理から導くので、各フェイズのクラスは統計を意識しない。
 */
final class StatsRecorder
{
    public const END_REASON_UNKNOWN = 1;
    public const END_REASON_PILE8 = 2;

    private const TABLE_INT = [
        'rounds_played', 'prep_steps', 'solo_draw_rounds', 'all_stop_rounds', 'end_reason',
    ];

    private const PLAYER_INT = [
        'cards_acquired', 'draw_count', 'stop_count', 'forced_choice_count', 'solo_draw_count', 'cards_removed',
        'max_output', 'pass_count', 'first_turn_count', 'charm_rounds', 'deck_size_final',
    ];

    public function __construct(private readonly StatStore $store)
    {
    }

    /**
     * 全ての統計を初期化する（setupNewGame）。
     */
    public function init(): void
    {
        foreach (self::TABLE_INT as $name) {
            $this->store->tableInit($name, 0);
        }
        foreach (self::PLAYER_INT as $name) {
            $this->store->playerInit($name, 0);
        }
        $this->store->playerInit('average_output', 0.0);
        $this->store->playerInit('unknown_acquired', false);
    }

    /**
     * 送る通知から統計を更新する。
     *
     * @param list<Notification> $notifications
     * @param int $roundNo 現在のラウンド番号（探査出力の平均の計算に使う）
     */
    public function recordNotifications(array $notifications, int $roundNo): void
    {
        foreach ($notifications as $n) {
            $playerId = isset($n->args['player_id']) ? (int) $n->args['player_id'] : 0;
            switch ($n->name) {
                case 'choiceRevealed':
                    $this->recordChoice($playerId, (string) ($n->args['choice'] ?? ''), $n->args['reason'] ?? null);
                    break;
                case 'cardSideways':
                    $this->store->playerInc('solo_draw_count', $playerId, 1);
                    break;
                case 'cardAcquired':
                    $this->store->playerInc('cards_acquired', $playerId, 1);
                    if ((int) ($n->args['card_no'] ?? 0) === 56) {
                        $this->store->playerSet('unknown_acquired', $playerId, true);
                    }
                    break;
                case 'cardsRemoved':
                    $this->store->playerInc('cards_removed', $playerId, ($n->args['attached_card'] ?? null) === null ? 1 : 2);
                    break;
                case 'exploreOutputDecided':
                    $this->recordOutput($playerId, (int) ($n->args['output'] ?? 0), $roundNo);
                    break;
                case 'exploreOrderDecided':
                    $order = $n->args['order'] ?? [];
                    if (is_array($order) && $order !== []) {
                        $this->store->playerInc('first_turn_count', (int) reset($order), 1);
                    }
                    break;
                case 'explorePassed':
                    $this->store->playerInc('pass_count', $playerId, 1);
                    break;
                case 'gameEndTriggered':
                    $this->store->tableSet('end_reason', ($n->args['reason'] ?? null) === GlobalValues::END_UNKNOWN
                        ? self::END_REASON_UNKNOWN
                        : self::END_REASON_PILE8);
                    break;
                case 'finalScore':
                    $this->store->playerSet('deck_size_final', $playerId, (int) ($n->args['deck_size'] ?? 0));
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * 状態 24 の開示後。選択者がいた回と、分岐の種類を数える。
     *
     * @param list<Notification> $notifications
     */
    public function recordPrepReveal(string $transition, array $notifications): void
    {
        $hasChoice = false;
        foreach ($notifications as $n) {
            if ($n->name === 'choiceRevealed') {
                $hasChoice = true;
                break;
            }
        }
        if ($hasChoice) {
            $this->store->tableInc('prep_steps', 1);
        }
        if ($transition === 'soloDraw') {
            $this->store->tableInc('solo_draw_rounds', 1);
        } elseif ($transition === 'allStop') {
            $this->store->tableInc('all_stop_rounds', 1);
        }
    }

    /**
     * 状態 53。ラウンドが 1 つ終わった。
     */
    public function recordRoundFinished(): void
    {
        $this->store->tableInc('rounds_played', 1);
    }

    /**
     * 状態 50。お守りの移動後の所持者に 1 ラウンドぶん加える。
     */
    public function recordCharmHolder(int $playerId): void
    {
        $this->store->playerInc('charm_rounds', $playerId, 1);
    }

    /**
     * 累積平均の更新。$previousRounds はこれまでに数えたラウンド数。
     */
    public static function runningAverage(float $previous, int $previousRounds, int $value): float
    {
        return ($previous * $previousRounds + $value) / ($previousRounds + 1);
    }

    private function recordChoice(int $playerId, string $choice, mixed $reason): void
    {
        if ($choice === 'draw') {
            $this->store->playerInc('draw_count', $playerId, 1);
        } elseif ($reason !== 'emptyDeck') {
            // 山札 0 枚による強制ストップは数えない
            $this->store->playerInc('stop_count', $playerId, 1);
        }
        if ($reason !== null) {
            $this->store->playerInc('forced_choice_count', $playerId, 1);
        }
    }

    private function recordOutput(int $playerId, int $output, int $roundNo): void
    {
        if ($output > (int) $this->store->playerGet('max_output', $playerId)) {
            $this->store->playerSet('max_output', $playerId, $output);
        }
        $average = self::runningAverage((float) $this->store->playerGet('average_output', $playerId), max(0, $roundNo - 1), $output);
        $this->store->playerSet('average_output', $playerId, $average);
    }
}
