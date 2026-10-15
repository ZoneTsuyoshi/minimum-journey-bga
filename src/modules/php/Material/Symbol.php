<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * シンボル（002 §3.1）。値は仕様書の英語識別子。
 */
enum Symbol: string
{
    case Earth = 'EARTH';
    case SolarSystem = 'SOLAR_SYSTEM';
    case MilkyWay = 'MILKY_WAY';
    case VirgoCluster = 'VIRGO_CLUSTER';
    case Laniakea = 'LANIAKEA';
    case Unknown = 'UNKNOWN';
    case Charm = 'CHARM';
}
