<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * ドロー時効果の選択で許されない操作。状態クラスが翻訳つきの UserException に変える。
 */
final class DrawRuleViolation extends \RuntimeException
{
    public const NOT_YOUR_CHOICE = 'notYourChoice';
    public const INVALID_TARGET = 'invalidTarget';
    public const ALREADY_DECIDED = 'alreadyDecided';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
