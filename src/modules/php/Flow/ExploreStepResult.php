<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査フェイズの 1 処理の結果。遷移名（不要なら null）、送る通知、手番のプレイヤー（41 のとき）。
 */
final class ExploreStepResult
{
    /**
     * @param list<Notification> $notifications
     */
    public function __construct(
        public readonly ?string $transition,
        public readonly array $notifications,
        public readonly ?int $playerId = null,
    ) {
    }
}
