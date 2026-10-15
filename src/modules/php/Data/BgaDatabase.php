<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

use Bga\GameFramework\Table;

/**
 * BGA フレームワークの DB メソッド（Table の静的メソッド）を包む実装。
 */
final class BgaDatabase implements Database
{
    public function execute(string $sql): void
    {
        Table::DbQuery($sql);
    }

    public function rows(string $sql): array
    {
        return array_values(Table::getObjectListFromDB($sql));
    }

    public function value(string $sql): mixed
    {
        return Table::getUniqueValueFromDB($sql);
    }

    public function lastInsertId(): int
    {
        return Table::DbGetLastId();
    }
}
