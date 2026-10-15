<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * DB 操作の最小限の口。BGA の静的メソッドを直接呼ばないことで、データ層を差し替え可能にする。
 */
interface Database
{
    public function execute(string $sql): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array;

    public function value(string $sql): mixed;

    public function lastInsertId(): int;
}
