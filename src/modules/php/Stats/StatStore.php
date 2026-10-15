<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Stats;

/**
 * 統計の読み書き口。BGA の tableStats / playerStats を包み、テストでは配列の実装に差し替える。
 */
interface StatStore
{
    public function tableInit(string $name, int|float|bool $value): void;

    public function tableSet(string $name, int|float|bool $value): void;

    public function tableInc(string $name, int $delta): void;

    public function playerInit(string $name, int|float|bool $value): void;

    public function playerSet(string $name, int $playerId, int|float|bool $value): void;

    public function playerInc(string $name, int $playerId, int $delta): void;

    public function playerGet(string $name, int $playerId): int|float|bool;
}
