<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\PrepChoice;

/**
 * 準備フェイズの純粋な判定（BGA にも DB にも依存しない。仕様書 006 §4、001 §5）。
 *
 * @phpstan-import-type NextChoiceRecords from \Bga\Games\MinimumJourney\Data\GlobalValues
 */
final class PrepClassifier
{
    private function __construct()
    {
    }

    /**
     * 状態 20 の分類（006 §4.6）。
     * - 054 の記録（force_draw）があれば、全員の準備終了を打ち消し、山札のある人はドロー確定、山札 0 枚の人はストップ確定。
     *   028・029・049 の記録は無視する（H3、N38）。山札 0 枚で準備終了済みの人は、そのまま準備終了で選択者にならない。
     * - なければ、準備終了していない人が選択者。028 → ドロー確定、029 → ストップ確定、山札 0 枚 → ストップ確定、
     *   049（山札あり）→ 後選び、それ以外は要選択。山札 0 枚の人は 028 でもドローできずストップ。
     *
     * @param list<int> $seatOrder 席順のプレイヤー ID
     * @param array<int, bool> $finished プレイヤー ID => 準備終了
     * @param array<int, int> $deckCounts プレイヤー ID => 山札の枚数
     * @param NextChoiceRecords $records
     */
    public static function plan(array $seatOrder, array $finished, array $deckCounts, array $records): PrepPlan
    {
        $cancelled = [];
        $fixed = [];
        $reasons = [];
        $need = [];
        $late = null;

        foreach ($seatOrder as $playerId) {
            $isFinished = $finished[$playerId] ?? false;
            $hasDeck = ($deckCounts[$playerId] ?? 0) > 0;

            if ($records['force_draw']) {
                if ($isFinished && !$hasDeck) {
                    continue;
                }
                if ($isFinished) {
                    $cancelled[] = $playerId;
                }
                if ($hasDeck) {
                    $fixed[$playerId] = PrepChoice::Draw;
                    $reasons[$playerId] = PrepPlan::REASON_BIG_BANG;
                } else {
                    $fixed[$playerId] = PrepChoice::Stop;
                    $reasons[$playerId] = PrepPlan::REASON_EMPTY_DECK;
                }
                continue;
            }

            if ($isFinished) {
                continue;
            }
            if (!$hasDeck) {
                $fixed[$playerId] = PrepChoice::Stop;
                $reasons[$playerId] = PrepPlan::REASON_EMPTY_DECK;
            } elseif (in_array($playerId, $records['draw_only'], true)) {
                $fixed[$playerId] = PrepChoice::Draw;
                $reasons[$playerId] = PrepPlan::REASON_MEMORY_ALBUM;
            } elseif (in_array($playerId, $records['stop_only'], true)) {
                $fixed[$playerId] = PrepChoice::Stop;
                $reasons[$playerId] = PrepPlan::REASON_SAFETY_DEVICE;
            } elseif ($records['late'] === $playerId) {
                $late = $playerId;
            } else {
                $need[] = $playerId;
            }
        }

        return new PrepPlan($cancelled, $fixed, $reasons, $need, $late);
    }

    /**
     * 開示後の計画（006 §4.7）。山札 0 枚でドローできない人はストップ扱い（実際に引けた人数で数える。D12）。
     * ストップした人が裏向きで引くのは、ドローが 1 人以上の回だけ（全員ストップの回は誰も引かない）。
     *
     * @param list<int> $seatOrder 席順のプレイヤー ID
     * @param array<int, PrepChoice|null> $choices プレイヤー ID => 選択（選択者だけ）
     * @param array<int, int> $deckCounts プレイヤー ID => 山札の枚数
     */
    public static function reveal(array $seatOrder, array $choices, array $deckCounts): RevealPlan
    {
        $drawers = [];
        $stoppers = [];
        foreach ($seatOrder as $playerId) {
            $choice = $choices[$playerId] ?? null;
            if ($choice === null) {
                continue;
            }
            if ($choice === PrepChoice::Draw && ($deckCounts[$playerId] ?? 0) > 0) {
                $drawers[] = $playerId;
            } else {
                $stoppers[] = $playerId;
            }
        }

        $effective = [];
        foreach ($drawers as $playerId) {
            $effective[$playerId] = PrepChoice::Draw;
        }
        foreach ($stoppers as $playerId) {
            $effective[$playerId] = PrepChoice::Stop;
        }

        return new RevealPlan($drawers, $stoppers, $drawers !== [], RoundFlow::revealTransition($effective));
    }
}
