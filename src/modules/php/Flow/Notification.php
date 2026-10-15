<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 送る通知 1 件（007 §10）。BGA に依存しない値で、状態クラスが notify->all / notify->player に渡す。
 * $to が null なら全員、プレイヤー ID なら本人のみ。
 */
final class Notification
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(
        public readonly ?int $to,
        public readonly string $name,
        public readonly string $message,
        public readonly array $args,
    ) {
    }
}
