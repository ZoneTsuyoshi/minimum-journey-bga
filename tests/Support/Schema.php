<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Support;

/**
 * テスト用のテーブルを作り直す。src/dbmodel.sql をそのまま流し、BGA が先に作る player テーブルは最小限で代用する。
 */
final class Schema
{
    public static function reset(PdoDatabase $db): void
    {
        foreach (['resolve_entry', 'card', 'player'] as $table) {
            $db->execute("DROP TABLE IF EXISTS `$table`");
        }
        // BGA が作る player テーブルの、データ層が使う列だけ
        $db->execute(
            'CREATE TABLE `player` ('
            . '`player_id` INT UNSIGNED NOT NULL, `player_no` INT NOT NULL DEFAULT 0, '
            . '`player_name` VARCHAR(100) NOT NULL DEFAULT \'\', PRIMARY KEY (`player_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        foreach (self::statements(dirname(__DIR__, 2) . '/src/dbmodel.sql') as $sql) {
            $db->execute($sql);
        }
    }

    /**
     * @return list<string>
     */
    private static function statements(string $path): array
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("cannot read $path");
        }
        $lines = array_filter(
            explode("\n", $sql),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        $statements = array_map('trim', explode(';', implode("\n", $lines)));
        return array_values(array_filter($statements, static fn(string $s): bool => $s !== ''));
    }
}
