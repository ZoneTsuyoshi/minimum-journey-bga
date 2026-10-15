<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 状態 ID（仕様書 006 §2）。transitions の行き先に使う。
 * 1 と 99 は BGA の予約（初期状態・ゲーム終了）なので使わない。本番稼働後は値を変えない。
 * BGA は States/ の中の全クラスを状態として読むので、この定数クラスは States/ に置かない。
 */
final class StateId
{
    public const ROUND_START = 10;

    public const PREP_STEP_START = 20;
    public const PREP_CHOICE = 21;
    public const PREP_LOCK = 22;
    public const PREP_CHOICE_LATE = 23;
    public const PREP_REVEAL = 24;

    public const DRAW_RESOLVE = 30;
    public const RETURN_POD_DECISION = 31;
    public const COPY_SOURCE_CHOICE = 32;
    public const WORMHOLE_CHOICE = 33;
    public const LASER_SIGHT_CHOICE = 34;
    public const SIMULTANEOUS_EFFECTS = 35;

    public const EXPLORE_START = 40;
    public const EXPLORE_TURN_START = 41;
    public const EXPLORE_ON_EXPLORE_CHOICE = 42;
    public const EXPLORE_CHOOSE = 43;
    public const ACQUIRE_DISPATCH = 44;
    public const ACQUIRE_EXCLUDE_CHOICE = 45;
    public const FREE_ACQUIRE_CHOICE = 46;
    public const DECK_EXCLUDE_CHOICE = 47;
    public const EXPLORE_TURN_END = 48;

    public const RETURN_AMULET = 50;
    public const AMULET_EXCLUDE_CHOICE = 51;
    public const RETURN_SHUFFLE = 52;
    public const RETURN_REFILL = 53;
    public const FINAL_SCORING = 60;

    /** ゲーム終了（BGA の予約。int で返す。U5） */
    public const GAME_END = 99;

    private function __construct()
    {
    }
}
