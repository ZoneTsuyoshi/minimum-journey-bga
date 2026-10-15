<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

/**
 * 準備フェイズで許されない操作。状態クラスが翻訳つきの UserException に変える。
 */
final class PrepRuleViolation extends \RuntimeException
{
    public const NOT_SELECTOR = 'notSelector';
    public const NOT_LATE_PLAYER = 'notLatePlayer';
    public const EMPTY_DECK = 'emptyDeck';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
