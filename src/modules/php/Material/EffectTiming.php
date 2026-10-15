<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * 効果タイミング（002 §3.2）。
 */
enum EffectTiming: string
{
    case OnDraw = 'ON_DRAW';
    case OnExplore = 'ON_EXPLORE';
    case DuringExplore = 'DURING_EXPLORE';
    case PowerMod = 'POWER_MOD';
    case FameMod = 'FAME_MOD';
    case OnAcquire = 'ON_ACQUIRE';
}
