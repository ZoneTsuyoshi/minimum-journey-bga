<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * card テーブルの 1 行（仕様書 007 §3.1）。不変。
 */
final class Card
{
    public function __construct(
        public readonly int $id,
        public readonly Zone $zone,
        public readonly int $locationArg,
        public readonly int $order,
        public readonly int $cardNo,
        public readonly int $art,
        public readonly bool $sideways,
    ) {
    }

    /**
     * @param array<string, mixed> $row card テーブルの行（値は文字列でも整数でもよい）
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['card_id'],
            Zone::from((string) $row['card_location']),
            (int) $row['card_location_arg'],
            (int) $row['card_order'],
            (int) $row['card_no'],
            (int) $row['card_art'],
            (int) $row['card_sideways'] === 1,
        );
    }
}
