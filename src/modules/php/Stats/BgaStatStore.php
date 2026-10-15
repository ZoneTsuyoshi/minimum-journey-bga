<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Stats;

use Bga\GameFramework\PlayerStats;
use Bga\GameFramework\TableStats;

/**
 * BGA の統計 API への薄い橋渡し。
 */
final class BgaStatStore implements StatStore
{
    public function __construct(
        private readonly TableStats $table,
        private readonly PlayerStats $player,
    ) {
    }

    public function tableInit(string $name, int|float|bool $value): void
    {
        $this->table->init($name, $value);
    }

    public function tableSet(string $name, int|float|bool $value): void
    {
        $this->table->set($name, $value);
    }

    public function tableInc(string $name, int $delta): void
    {
        $this->table->inc($name, $delta);
    }

    public function playerInit(string $name, int|float|bool $value): void
    {
        $this->player->init($name, $value);
    }

    public function playerSet(string $name, int $playerId, int|float|bool $value): void
    {
        $this->player->set($name, $value, $playerId);
    }

    public function playerInc(string $name, int $playerId, int $delta): void
    {
        $this->player->inc($name, $delta, $playerId);
    }

    public function playerGet(string $name, int $playerId): int|float|bool
    {
        return $this->player->get($name, $playerId);
    }
}
