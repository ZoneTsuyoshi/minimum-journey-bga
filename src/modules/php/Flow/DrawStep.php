<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * ドロー時効果の解決エンジン（状態 30）または割り込み状態の 1 処理の結果。
 * 遷移名（割り込み状態から戻るときは null のこともある）、送る通知、割り込み先で手番になるプレイヤー。
 */
final class DrawStep
{
    /**
     * @param list<Notification> $notifications
     */
    public function __construct(
        public readonly ?string $transition,
        public readonly array $notifications,
        public readonly ?int $activePlayer = null,
    ) {
    }
}
