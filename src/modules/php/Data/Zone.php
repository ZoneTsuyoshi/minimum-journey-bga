<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * カードの置き場所の種類（仕様書 007 §3.2 の card_location）。
 * card_location_arg の意味は種類ごとに異なる（プレイヤー ID、探査コスト、付け先のカード ID、0）。
 */
enum Zone: string
{
    case Deck = 'deck';
    case Prep = 'prep';
    case Attached = 'attached';
    case Facedown = 'facedown';
    case Acquired = 'acquired';
    case ExploreDeck = 'explore_deck';
    case Row = 'row';
    case Unknown = 'unknown';
    case Removed = 'removed';
    case SetupRemoved = 'setup_removed';
    case Charm = 'charm';

    /**
     * 山として扱う置き場所（順序と中身を公開しない。007 §3.4）。
     */
    public function isPile(): bool
    {
        return match ($this) {
            self::Deck, self::ExploreDeck, self::Facedown => true,
            default => false,
        };
    }
}
