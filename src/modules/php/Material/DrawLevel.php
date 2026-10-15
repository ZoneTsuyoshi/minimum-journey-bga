<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * ドロー時効果の解決優先度（010 §4.1、008 §1.4）。
 * COPY は 008（観測衛星）専用で、コピー元を選ぶ位置が P2A の直後・P3 の前。
 */
enum DrawLevel: string
{
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';
    case P2A = 'P2A';
    case Copy = 'COPY';
    case P3 = 'P3';
    case P4 = 'P4';
    case P5Seq = 'P5_SEQ';
    case P5Sim = 'P5_SIM';
    case P6 = 'P6';

    /** 解決順を表す数値（008 §1.4 の「順序」列）。小さいほど先に解決する。 */
    public function order(): int
    {
        return match ($this) {
            self::P0 => 0,
            self::P1 => 10,
            self::P2 => 20,
            self::P2A => 25,
            self::Copy => 27,
            self::P3 => 30,
            self::P4 => 40,
            self::P5Seq => 50,
            self::P5Sim => 51,
            self::P6 => 60,
        };
    }
}
