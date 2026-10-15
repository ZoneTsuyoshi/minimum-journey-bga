<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\GlobalValues;

/**
 * 終了フラグを立てる処理（006 §6.4）。探査（44 相当）と補充（53）で共有する。
 */
final class GameEndTrigger
{
    private function __construct()
    {
    }

    /**
     * すでに立っていれば何もしない（先に起きた理由を残す）。その場では終了しない。
     *
     * @param callable(int): string $playerName
     * @return list<Notification>
     */
    public static function trigger(GlobalValues $globals, callable $playerName, string $reason, int $playerId): array
    {
        if ($globals->gameEndTriggered()) {
            return [];
        }
        $globals->setGameEndTriggered(true);
        $globals->setEndReason($reason);

        return [new Notification(
            null,
            'gameEndTriggered',
            clienttranslate('The game ends at the end of this round (${reason_label})'),
            [
                'reason' => $reason,
                'reason_label' => $reason === GlobalValues::END_UNKNOWN
                    ? clienttranslate('the Unknown Region was acquired')
                    : clienttranslate('the 8-cost pile is empty'),
                'player_id' => $playerId,
                'player_name' => $playerId === 0 ? '' : $playerName($playerId),
                'i18n' => ['reason_label'],
            ],
        )];
    }
}
