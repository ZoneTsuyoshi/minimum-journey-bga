<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\PrepChoice;

/**
 * 準備選択 1 回分の開始時の分類結果（006 §2.2 の状態 20、§4.6）。純粋な値。
 */
final class PrepPlan
{
    public const REASON_MEMORY_ALBUM = 'memoryAlbum';
    public const REASON_SAFETY_DEVICE = 'safetyDevice';
    public const REASON_BIG_BANG = 'bigBang';
    public const REASON_EMPTY_DECK = 'emptyDeck';

    /**
     * @param list<int> $cancelled 054 で準備終了を打ち消されたプレイヤー（山札 0 枚の人は含めない。N5）
     * @param array<int, PrepChoice> $fixed 選択が確定したプレイヤー（強制・自動）
     * @param array<int, string> $reasons 確定の理由（$fixed と同じキー）
     * @param list<int> $needChoice 21 で選ぶプレイヤー（席順）
     * @param int|null $late 049 の後選びのプレイヤー
     */
    public function __construct(
        public readonly array $cancelled,
        public readonly array $fixed,
        public readonly array $reasons,
        public readonly array $needChoice,
        public readonly ?int $late,
    ) {
    }

    /**
     * 20 の遷移名。要選択者がいれば 21、いなければ 22 へ（後選びだけでも 22）。
     */
    public function transition(): string
    {
        return $this->needChoice === [] ? 'skipToLock' : 'needChoice';
    }
}
