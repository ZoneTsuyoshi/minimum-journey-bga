<?php

declare(strict_types=1);

// BGA のフレームワーク本体が無い環境で、データ層が型として使うクラスだけを最小限に用意する。
// 本物は BGA Studio 上でだけ読み込まれる。ここでの定義は tests/ の中だけで使う。

namespace Bga\GameFramework\Db {
    if (!class_exists(Globals::class, false)) {
        abstract class Globals
        {
            abstract public function get(string $name, mixed $defaultValue = null, ?string $class = null): mixed;

            abstract public function set(string $name, mixed $value): void;

            abstract public function inc(string $name, int $step): int;
        }
    }
}
