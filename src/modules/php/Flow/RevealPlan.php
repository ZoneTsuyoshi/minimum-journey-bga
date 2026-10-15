<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 開示後の処理の計画（006 §2.2 の状態 24、001 §5 ②③）。純粋な値。
 */
final class RevealPlan
{
    /**
     * @param list<int> $drawers 実際に山札から引くプレイヤー（席順）
     * @param list<int> $stoppers ストップしたプレイヤー（席順。山札 0 枚で引けなかった人を含む）
     * @param bool $stoppersDrawFacedown ストップした人が裏向きで引くか（ドローが 1 人以上の回だけ）
     * @param string $transition allStop / soloDraw / resolve
     */
    public function __construct(
        public readonly array $drawers,
        public readonly array $stoppers,
        public readonly bool $stoppersDrawFacedown,
        public readonly string $transition,
    ) {
    }
}
