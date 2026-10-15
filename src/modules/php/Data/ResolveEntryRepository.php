<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * resolve_entry テーブル（仕様書 007 §4.4）の読み書き。1 回の解決（状態 30）の間だけ行が存在する。
 * entry_decision（043 の決定）は全員が決めるまで本人以外に送らない。この層は値を返すだけで、送り先の判断は呼び出し側が行う。
 */
final class ResolveEntryRepository
{
    private const COLUMNS = '`entry_id`, `entry_seq`, `entry_player`, `entry_card_id`, `entry_card_no`, `entry_origin`, '
        . '`entry_host`, `entry_level`, `entry_eff_level`, `entry_status`, `entry_copy_no`, `entry_decision`';

    public function __construct(private readonly Database $db)
    {
    }

    public function insert(
        int $player,
        ?int $cardId,
        int $cardNo,
        string $origin,
        ?int $host,
        ?int $level,
        ?int $effLevel,
        string $status,
    ): int {
        $seq = (int) $this->db->value('SELECT COALESCE(MAX(`entry_seq`), -1) + 1 FROM `resolve_entry`');
        $this->db->execute(sprintf(
            'INSERT INTO `resolve_entry` (`entry_seq`, `entry_player`, `entry_card_id`, `entry_card_no`, `entry_origin`, '
            . '`entry_host`, `entry_level`, `entry_eff_level`, `entry_status`) VALUES (%d, %d, %s, %d, \'%s\', %s, %s, %s, \'%s\')',
            $seq,
            $player,
            self::sqlInt($cardId),
            $cardNo,
            $origin,
            self::sqlInt($host),
            self::sqlInt($level),
            self::sqlInt($effLevel),
            $status,
        ));
        return $this->db->lastInsertId();
    }

    /**
     * キューの順（seq の昇順）の全行。
     *
     * @return list<ResolveEntry>
     */
    public function all(): array
    {
        return array_map(
            static fn(array $row): ResolveEntry => ResolveEntry::fromRow($row),
            $this->db->rows('SELECT ' . self::COLUMNS . ' FROM `resolve_entry` ORDER BY `entry_seq` ASC'),
        );
    }

    public function get(int $id): ResolveEntry
    {
        $rows = $this->db->rows('SELECT ' . self::COLUMNS . ' FROM `resolve_entry` WHERE `entry_id` = ' . $id);
        if ($rows === []) {
            throw new \InvalidArgumentException("resolve entry not found: $id");
        }
        return ResolveEntry::fromRow($rows[0]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->update($id, "`entry_status` = '$status'");
    }

    public function setCardId(int $id, ?int $cardId): void
    {
        $this->update($id, '`entry_card_id` = ' . self::sqlInt($cardId));
    }

    public function setCopy(int $id, int $copyNo, int $effLevel): void
    {
        $this->update($id, "`entry_copy_no` = $copyNo, `entry_eff_level` = $effLevel");
    }

    public function setDecision(int $id, ?int $decision): void
    {
        $this->update($id, '`entry_decision` = ' . self::sqlInt($decision));
    }

    public function clearDecisions(): void
    {
        $this->db->execute('UPDATE `resolve_entry` SET `entry_decision` = NULL');
    }

    public function clear(): void
    {
        $this->db->execute('DELETE FROM `resolve_entry`');
    }

    private function update(int $id, string $assignment): void
    {
        $this->db->execute("UPDATE `resolve_entry` SET $assignment WHERE `entry_id` = $id");
    }

    private static function sqlInt(?int $v): string
    {
        return $v === null ? 'NULL' : (string) $v;
    }
}
