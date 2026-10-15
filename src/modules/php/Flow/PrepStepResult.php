<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 準備フェイズの 1 処理の結果。遷移名（GAME 状態の戻り値。不要なら null）と、送る通知。
 */
final class PrepStepResult
{
    /**
     * @param list<Notification> $notifications
     * @param bool $firstChoice 選択が null から値に変わった最初の 1 回か（アクション用）
     * @param array<int, \Bga\Games\MinimumJourney\Data\Card> $drawn 開示でドローしたカード（プレイヤー ID => カード）。解決（30）のキューの元
     */
    public function __construct(
        public readonly ?string $transition,
        public readonly array $notifications,
        public readonly bool $firstChoice = false,
        public readonly array $drawn = [],
    ) {
    }
}
