<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\Card;
use Bga\Games\MinimumJourney\Data\CardRepository;

/**
 * カードをゲームから除外して通知（N20 cardsRemoved）を作る（004・013・055・058）。
 * 046 を除外すると付加カードも一緒に除外する（同一カードとして扱う。007 No.5）。
 */
final class CardRemoval
{
    /** @var callable(int): string */
    private $playerName;

    /**
     * @param callable(int): string $playerName
     */
    public function __construct(private readonly CardRepository $cards, callable $playerName)
    {
        $this->playerName = $playerName;
    }

    /**
     * @param Card $card 除外するカード。付加カードではなく 046 本体（または通常のカード）を渡す
     * @param string $sourceName 除外した効果のカード名（英語）
     */
    public function remove(int $playerId, Card $card, string $sourceName): Notification
    {
        $attached = $this->cards->attachedTo($card)[0] ?? null;
        $this->cards->removeFromGame([$card]);

        return new Notification(
            null,
            'cardsRemoved',
            clienttranslate('${player_name} removes ${card_name} from the game (${source_name})'),
            [
                'player_id' => $playerId,
                'player_name' => ($this->playerName)($playerId),
                'card' => DrawEffects::cardView($card),
                'card_name' => DrawEffects::nameOf($card->cardNo),
                'attached_card' => $attached === null ? null : DrawEffects::cardView($attached),
                'from' => $card->zone->value,
                'source_name' => $sourceName,
                'i18n' => ['card_name', 'source_name'],
            ],
        );
    }
}
