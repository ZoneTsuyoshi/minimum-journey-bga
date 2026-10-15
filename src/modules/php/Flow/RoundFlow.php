<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\PrepChoice;

/**
 * 状態遷移の判定のうち、BGA にも DB にも依存しない部分（純粋関数）。単体テストの対象。
 */
final class RoundFlow
{
    private function __construct()
    {
    }

    /**
     * 準備の開示後の分岐（006 §2.2 の状態 24）。ドロー 0 人 → allStop、1 人 → soloDraw、2 人以上 → resolve。
     *
     * @param array<int, PrepChoice|null> $choices プレイヤー ID => 選択
     */
    public static function revealTransition(array $choices): string
    {
        $draws = count(array_filter($choices, static fn(?PrepChoice $c): bool => $c === PrepChoice::Draw));
        return match (true) {
            $draws === 0 => 'allStop',
            $draws === 1 => 'soloDraw',
            default => 'resolve',
        };
    }

    /**
     * 全員が選び終えたか（選択者全員の選択が埋まっている）。
     *
     * @param list<int> $selectors
     * @param array<int, PrepChoice|null> $choices
     */
    public static function allChosen(array $selectors, array $choices): bool
    {
        foreach ($selectors as $playerId) {
            if (($choices[$playerId] ?? null) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * 次の探査手番のプレイヤー。全員終わっていれば null（006 §6.1 の状態 41）。
     *
     * @param list<int> $order
     */
    public static function currentExplorer(array $order, int $index): ?int
    {
        return $order[$index] ?? null;
    }

    /**
     * 補充後の分岐（006 §2.5 の状態 53）。終了フラグがあれば end → 60、なければ nextRound → 10。
     */
    public static function afterRefill(bool $gameEndTriggered): string
    {
        return $gameEndTriggered ? 'end' : 'nextRound';
    }

    /**
     * ゲーム進行度（0〜99。006 §8）。探査山札の消費で測る。
     *
     * @param int $pile8Initial 開始時のコスト 8 の探査山札の枚数
     * @param int $pile8 現在のコスト 8 の探査山札の枚数
     * @param int $totalInitial 開始時の全探査山札の合計
     * @param int $total 現在の全探査山札の合計
     */
    public static function progression(int $pile8Initial, int $pile8, int $totalInitial, int $total): int
    {
        $g8 = $pile8Initial > 0 ? 1 - $pile8 / $pile8Initial : 0.0;
        $gAll = $totalInitial > 0 ? 1 - $total / $totalInitial : 0.0;
        $progress = (int) floor(100 * max($g8, 0.8 * $gAll));
        return max(0, min($progress, 99));
    }
}
