<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * 準備選択（player.prep_choice。仕様書 007 §4.1）。未選択は null で表す。
 */
enum PrepChoice: string
{
    case Draw = 'draw';
    case Stop = 'stop';
}
