<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * カード区分（002 §3.3）。
 */
enum CardCategory: string
{
    case Starter = 'STARTER';
    case Drift = 'DRIFT';
    case UnknownBand = 'UNKNOWN_BAND';
    case CharmCard = 'CHARM_CARD';
}
