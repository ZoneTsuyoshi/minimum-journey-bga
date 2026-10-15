<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Support;

use Bga\Games\MinimumJourney\Stats\StatStore;

/**
 * テスト用の統計の入れ物（配列）。
 */
final class ArrayStatStore implements StatStore
{
    /** @var array<string, int|float|bool> */
    public array $table = [];

    /** @var array<string, array<int, int|float|bool>> 名前 => プレイヤー ID => 値 */
    public array $player = [];

    /** @var array<string, int|float|bool> */
    private array $playerDefaults = [];

    public function tableInit(string $name, int|float|bool $value): void
    {
        $this->table[$name] = $value;
    }

    public function tableSet(string $name, int|float|bool $value): void
    {
        $this->table[$name] = $value;
    }

    public function tableInc(string $name, int $delta): void
    {
        $this->table[$name] = (int) ($this->table[$name] ?? 0) + $delta;
    }

    public function playerInit(string $name, int|float|bool $value): void
    {
        $this->playerDefaults[$name] = $value;
        $this->player[$name] = [];
    }

    public function playerSet(string $name, int $playerId, int|float|bool $value): void
    {
        $this->player[$name][$playerId] = $value;
    }

    public function playerInc(string $name, int $playerId, int $delta): void
    {
        $this->player[$name][$playerId] = (int) $this->playerGet($name, $playerId) + $delta;
    }

    public function playerGet(string $name, int $playerId): int|float|bool
    {
        return $this->player[$name][$playerId] ?? $this->playerDefaults[$name] ?? 0;
    }
}
