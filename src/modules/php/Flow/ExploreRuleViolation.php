<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 探査フェイズの規則違反（探査できない対象の指定など）。状態クラスがユーザー向けの翻訳つきエラーにする。
 */
final class ExploreRuleViolation extends \RuntimeException
{
    public const NOT_YOUR_TURN = 'notYourTurn';
    public const NOT_A_TARGET = 'notATarget';
    public const ALREADY_EXPLORED = 'alreadyExplored';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
