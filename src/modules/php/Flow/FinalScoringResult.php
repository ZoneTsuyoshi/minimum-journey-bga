<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 最終得点の計算結果（状態 60）。送る通知と、プレイヤーごとの内訳（playerScore と playerScoreAux に書く値の元）。
 */
final class FinalScoringResult
{
    /**
     * @param list<Notification> $notifications
     * @param array<int, FinalScore> $scores プレイヤー ID => 内訳
     */
    public function __construct(
        public readonly array $notifications,
        public readonly array $scores,
    ) {
    }
}
