<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * resolve_entry テーブルの 1 行（仕様書 007 §4.4）。ドロー時効果の解決キューの要素。不変。
 */
final class ResolveEntry
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_DONE = 'done';

    public const ORIGIN_DRAWN = 'drawn';
    public const ORIGIN_ATTACHED = 'attached';

    public function __construct(
        public readonly int $id,
        public readonly int $seq,
        public readonly int $player,
        public readonly ?int $cardId,
        public readonly int $cardNo,
        public readonly string $origin,
        public readonly ?int $host,
        public readonly ?int $level,
        public readonly ?int $effLevel,
        public readonly string $status,
        public readonly ?int $copyNo,
        public readonly ?int $decision,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $nullable = static fn(mixed $v): ?int => $v === null ? null : (int) $v;
        return new self(
            (int) $row['entry_id'],
            (int) $row['entry_seq'],
            (int) $row['entry_player'],
            $nullable($row['entry_card_id']),
            (int) $row['entry_card_no'],
            (string) $row['entry_origin'],
            $nullable($row['entry_host']),
            $nullable($row['entry_level']),
            $nullable($row['entry_eff_level']),
            (string) $row['entry_status'],
            $nullable($row['entry_copy_no']),
            $nullable($row['entry_decision']),
        );
    }

    /**
     * 実際に働く効果のカード番号（008 がコピーしたときはコピー元）。
     */
    public function effectNo(): int
    {
        return $this->copyNo ?? $this->cardNo;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }
}
