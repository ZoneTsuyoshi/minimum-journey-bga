<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * 探査力変動の作用先（008 §1.2 power_mod_target）。
 */
enum PowerModTarget: string
{
    case Own = 'SELF';
    case Next = 'NEXT';
    case Prev = 'PREV';
    case All = 'ALL';
}
