<?php

declare(strict_types=1);

namespace MinimumJourneyTests\Support;

use Bga\GameFramework\Db\Globals;

/**
 * BGA の globals（bga_globals テーブル）の代わりに配列に値を持つ。
 * 本物は PHP の値を JSON で保存して取り出すため、set のたびに JSON を通して型の変化も再現する。
 */
final class ArrayGlobals extends Globals
{
    /** @var array<string, string> */
    private array $store = [];

    public function get(string $name, mixed $defaultValue = null, ?string $class = null): mixed
    {
        if (!array_key_exists($name, $this->store)) {
            return $defaultValue;
        }
        return json_decode($this->store[$name], true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(string $name, mixed $value): void
    {
        $this->store[$name] = json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function inc(string $name, int $step): int
    {
        $value = (int) $this->get($name, 0) + $step;
        $this->set($name, $value);
        return $value;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->store);
    }
}
