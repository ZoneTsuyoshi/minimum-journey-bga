<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * 探査力変動の処理段階（011、008 §1.4）。S1〜S4。
 */
enum PowerModStage: string
{
    case Rewrite = 'REWRITE';
    case Negate = 'NEGATE';
    case Own = 'SELF';
    case Reference = 'REFERENCE';

    /** 段階番号 1〜4（S1〜S4）。 */
    public function order(): int
    {
        return match ($this) {
            self::Rewrite => 1,
            self::Negate => 2,
            self::Own => 3,
            self::Reference => 4,
        };
    }
}
