<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * ドロー時効果の解決エンジンのカーソル（グローバル値 resolve_level。仕様書 007 §4.2、006 §5.2）。
 */
enum ResolveLevel: string
{
    case Init = 'INIT';
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';
    case P2Wait = 'P2_WAIT';
    case P2A = 'P2A';
    case Copy = 'COPY';
    case CopyWait = 'COPY_WAIT';
    case P3 = 'P3';
    case P4 = 'P4';
    case P4Wait = 'P4_WAIT';
    case P5Seq = 'P5_SEQ';
    case P5Sim = 'P5_SIM';
    case P6 = 'P6';
}
