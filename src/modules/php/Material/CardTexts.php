<?php

declare(strict_types=1);

// 行の長い英文を 1 つの文字列として持つ（clienttranslate が 1 つのリテラルを要求するため）
// phpcs:disable Generic.Files.LineLength

namespace Bga\Games\MinimumJourney\Material;

/**
 * カードの英語名と英語の効果テキスト（009 §6）。日本語の原文は 002 §5、解釈は 008。
 * BGA の翻訳機構に載せるため、全ての文字列を clienttranslate() で包む。
 * 本文中の {power} {fame} {cost} {earth} {solar} {milky} {virgo} {laniakea} {unknown} {charm} {flag} {crew} は、
 * クライアントがアイコン付きの用語に置き換える（英語の用語はクライアントの _() で翻訳する）。
 * 効果は効果タイミングごとの段落で、複数のタイミングが同じ文を共有するカード（007）は 1 つの段落に複数のタイミングを持つ。
 */
final class CardTexts
{
    /**
     * @return array<string, array{name: string, effects: list<array{timings: list<EffectTiming>, text: string}>}> カード番号 => 英語の内容
     */
    public static function all(): array
    {
        return [
            '001' => [
                'name' => clienttranslate('Flag'),
                'effects' => [],
            ],
            '002' => [
                'name' => clienttranslate('Crew'),
                'effects' => [],
            ],
            '003' => [
                'name' => clienttranslate('Starter Ship'),
                'effects' => [],
            ],
            '004' => [
                'name' => clienttranslate('Colony Camp'),
                'effects' => [
                    ['timings' => [EffectTiming::OnAcquire], 'text' => clienttranslate('Choose 1 face-up card in your prep area. Remove that card from the game.')],
                ],
            ],
            '005' => [
                'name' => clienttranslate('Robot Arm'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Look at the top 2 cards of your deck, then put them back on top of your deck in any order.')],
                ],
            ],
            '006' => [
                'name' => clienttranslate('Torn Map'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('During your exploration turn, the {cost} of cards in the exploration area that have {milky} or {laniakea} is reduced by 1.')],
                ],
            ],
            '007' => [
                'name' => clienttranslate('Laser Sight'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw, EffectTiming::DuringExplore], 'text' => clienttranslate('Choose 1 card in the exploration rows. For the rest of the round, no player other than you can explore that card. {unknown} cannot be chosen.')],
                ],
            ],
            '008' => [
                'name' => clienttranslate('Observation Satellite'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Choose 1 card with {solar} or {milky} in the exploration rows and apply its When Drawn effect.')],
                ],
            ],
            '009' => [
                'name' => clienttranslate('Capricious Drill'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('You may return the card prepared right before this one to your deck. If you do, shuffle your deck and draw 1 card. The When Drawn effect of the newly drawn card does not trigger.')],
                ],
            ],
            '010' => [
                'name' => clienttranslate('Efficient Development Manual'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Look at the top card of your deck. Put it back on top of your deck or on the bottom of your deck.')],
                ],
            ],
            '011' => [
                'name' => clienttranslate('Wide-Area Radar'),
                'effects' => [
                    ['timings' => [EffectTiming::OnExplore], 'text' => clienttranslate('You may choose an exploration pile. If you do, add the top card of the chosen pile face up to the exploration rows.')],
                ],
            ],
            '012' => [
                'name' => clienttranslate('Towable Container'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('You may explore 1 more card, as long as the total {cost} of the cards explored this round stays at or below your exploration output.')],
                ],
            ],
            '013' => [
                'name' => clienttranslate('Local Map'),
                'effects' => [
                    ['timings' => [EffectTiming::OnExplore], 'text' => clienttranslate('During your exploration turn, you may remove this card in your prep area from the game. If you do, the {cost} of cards in the exploration area is reduced by 1.')],
                ],
            ],
            '014' => [
                'name' => clienttranslate('Recycler'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('You may return all cards with {earth} in your prep area to your deck. If you do, shuffle your deck and prepare as many cards as you returned. The When Drawn effects of the newly drawn cards do not trigger.')],
                ],
            ],
            '015' => [
                'name' => clienttranslate('Rover Probe'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('If your exploration output is the lowest among all players, during your exploration turn the {cost} of cards in the exploration area is reduced by 2.')],
                ],
            ],
            '016' => [
                'name' => clienttranslate('Patriot Robot'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If a {flag} is prepared in your prep area, this card gets +3 {power}.')],
                ],
            ],
            '017' => [
                'name' => clienttranslate('Relay Camera'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If a {crew} was prepared before this card, this card gets +2 {power}.')],
                ],
            ],
            '018' => [
                'name' => clienttranslate('Gamble Charger'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('This card gets +1 {power} for each of your cards prepared up to and including this one (for example, if this is the 3rd card prepared, +3 {power}).')],
                ],
            ],
            '019' => [
                'name' => clienttranslate('Ore Mixer'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('The next card you prepare has its {fame} treated as its {power}, and its Exploration Power Change effects are ignored. However, if that card has a Fame Change effect, this card\'s effect does not trigger.')],
                ],
            ],
            '020' => [
                'name' => clienttranslate('Solar System Exploration Manual'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('You may choose 1 card with {solar} from your deck and set it aside face down. Shuffle your deck, then place the set-aside card face down on top of your deck.')],
                ],
            ],
            '021' => [
                'name' => clienttranslate('Virgo Cluster Map'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('During your exploration turn, the {cost} of cards in the exploration area that have {virgo} is reduced by 1.')],
                ],
            ],
            '022' => [
                'name' => clienttranslate('Small Satellite'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If the number of cards in your prep area is equal to or less than the number of cards in the prep area of the player to your left, this card gets +1 {power}.')],
                ],
            ],
            '023' => [
                'name' => clienttranslate('Fussy Control Stick'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If the card prepared right before this one does not have {solar}, this card gets +1 {power} (also +1 {power} if this is the first card prepared).')],
                ],
            ],
            '024' => [
                'name' => clienttranslate('Overflowing Fuel'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Each player who has already finished preparing prepares 1 card from the top of their own deck. The When Drawn effects of the newly drawn cards do not trigger.')],
                ],
            ],
            '025' => [
                'name' => clienttranslate('Clouded Space Telescope'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('You cannot explore cards in the exploration rows. However, you can explore {unknown}.')],
                ],
            ],
            '026' => [
                'name' => clienttranslate('Oversized Engine'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('During your exploration turn, you cannot explore cards that have {solar} or {milky}.')],
                ],
            ],
            '027' => [
                'name' => clienttranslate('Nearly Expired Space Food'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('If your exploration turn is the last, during your exploration turn the {cost} of cards in the exploration area is increased by 1.')],
                ],
            ],
            '028' => [
                'name' => clienttranslate('Memory Album'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('In your next preparation choice, you can only choose Draw.')],
                ],
            ],
            '029' => [
                'name' => clienttranslate('Safety Device'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('In your next preparation choice, you can only choose Stop.')],
                ],
            ],
            '030' => [
                'name' => clienttranslate('Nuclear Thermal Booster'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('The {power} of the card prepared right before this one becomes 0, and its Exploration Power Change effect does not trigger.')],
                ],
            ],
            '031' => [
                'name' => clienttranslate('Unbreakable Mirror'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('This card gets additional {power} equal to the {power} of the card prepared right before this one. If this is the first card prepared, this card gets +1 {power}.')],
                ],
            ],
            '032' => [
                'name' => clienttranslate('Pedal Generator'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('This card gets +1 {power} for each card with {earth} in your prep area.')],
                    ['timings' => [EffectTiming::FameMod], 'text' => clienttranslate('+1 {fame} for each card with {earth} among all your cards.')],
                ],
            ],
            '033' => [
                'name' => clienttranslate('Solar Power Plant'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('This card gets +1 {power} for each card with {solar} in your prep area.')],
                    ['timings' => [EffectTiming::FameMod], 'text' => clienttranslate('+1 {fame} for each card with {solar} among all your cards.')],
                ],
            ],
            '034' => [
                'name' => clienttranslate('Dust Vacuum'),
                'effects' => [
                    ['timings' => [EffectTiming::OnAcquire], 'text' => clienttranslate('Choose 1 card in the exploration area that has only {solar} or {milky} (no other symbols) and acquire it for free.')],
                ],
            ],
            '035' => [
                'name' => clienttranslate('Milky Way Exploration Manual'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('You may choose 1 card with {milky} from your deck and set it aside face down. Shuffle your deck, then place the set-aside card face down on top of your deck.')],
                ],
            ],
            '036' => [
                'name' => clienttranslate('Makeshift Wormhole'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Turn the top card of your deck face up. You may swap it with a card of the same {cost} in the exploration rows and add the swapped-in card to your deck. Shuffle your deck.')],
                ],
            ],
            '037' => [
                'name' => clienttranslate('Fluffy Bed'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('If this card is the last card you prepared, during your exploration turn the {cost} of cards in the exploration area is reduced by 1.')],
                ],
            ],
            '038' => [
                'name' => clienttranslate('Rough Map'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('If your exploration turn is the first, during your exploration turn the {cost} of cards in the exploration area is reduced by 1.')],
                ],
            ],
            '039' => [
                'name' => clienttranslate('Jamming Transmitter'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('In this draw, only the When Drawn effects of each player\'s cards with {virgo} trigger.')],
                ],
            ],
            '040' => [
                'name' => clienttranslate('Pioneer\'s Beacon'),
                'effects' => [
                    ['timings' => [EffectTiming::OnAcquire], 'text' => clienttranslate('When you acquire this card in the exploration phase, in the next return phase, place this card on top of your deck after shuffling your deck.')],
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If this card is the first card prepared, this card gets +1 {power}.')],
                ],
            ],
            '041' => [
                'name' => clienttranslate('Pretty Little Box'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If you hold the {charm}, this card gets +1 {power}.')],
                    ['timings' => [EffectTiming::FameMod], 'text' => clienttranslate('At the end of the game, if you hold the {charm}, +1 {fame}.')],
                ],
            ],
            '042' => [
                'name' => clienttranslate('Upgrade Patch'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('If the card prepared right before this one has {solar} or {milky}, this card gets +1 {power}.')],
                    ['timings' => [EffectTiming::FameMod], 'text' => clienttranslate('+1 {fame} for each pair of a {solar} card and a {milky} card among all your cards.')],
                ],
            ],
            '043' => [
                'name' => clienttranslate('Runaway Transfer Pod'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Other players may return the card they drew in this draw to their deck and shuffle. If they do, that player prepares 1 card. Decide whether to apply this effect before the When Drawn effects trigger. The When Drawn effect of the newly prepared card does not trigger.')],
                ],
            ],
            '044' => [
                'name' => clienttranslate('Knowledge Database'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('This card gets +1 {power} for each card in your prep area.')],
                ],
            ],
            '045' => [
                'name' => clienttranslate('Upgrade Parts'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('This card gets +1 {power} for each kind of {earth}, {solar}, {milky}, {virgo} and {laniakea} in your prep area.')],
                    ['timings' => [EffectTiming::FameMod], 'text' => clienttranslate('+1 {fame} for each kind of {earth}, {solar}, {milky}, {virgo} and {laniakea} among all your cards.')],
                ],
            ],
            '046' => [
                'name' => clienttranslate('Some Part'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Draw 1 card from your deck and place it beside this card. That card does not count toward the number of cards in your prep area. Its {power}, effects and symbols are added to this card.')],
                ],
            ],
            '047' => [
                'name' => clienttranslate('Map to Glory'),
                'effects' => [
                    ['timings' => [EffectTiming::DuringExplore], 'text' => clienttranslate('During your exploration turn, the {cost} of {unknown} is reduced by 2.')],
                    ['timings' => [EffectTiming::FameMod], 'text' => clienttranslate('If you have {unknown} among all your cards, +3 {fame}.')],
                ],
            ],
            '048' => [
                'name' => clienttranslate('Gravity Inverter'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('All cards in your prep area have their {fame} treated as their {power}, and their Exploration Power Change effects do not trigger. However, this card\'s effect does not apply to cards with a Fame Change effect.')],
                ],
            ],
            '049' => [
                'name' => clienttranslate('Time Stopper'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('In the next preparation choice, after the other players have chosen Draw or Stop, you choose Draw or Stop.')],
                ],
            ],
            '050' => [
                'name' => clienttranslate('Space Exploration Manual'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('You may choose 1 card from your deck and set it aside face down. Shuffle your deck, then place the set-aside card face down on top of your deck.')],
                ],
            ],
            '051' => [
                'name' => clienttranslate('Zeus Material'),
                'effects' => [],
            ],
            '052' => [
                'name' => clienttranslate('Scales of Fate'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('Look at the top 2 cards of your deck and choose 1 of them. Put the chosen card back on top of your deck and put the other card on the bottom of your deck.')],
                ],
            ],
            '053' => [
                'name' => clienttranslate('Superflare Trigger'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('The When Drawn effects of other players\' cards drawn in this draw do not trigger.')],
                ],
            ],
            '054' => [
                'name' => clienttranslate('Seed of the Big Bang'),
                'effects' => [
                    ['timings' => [EffectTiming::OnDraw], 'text' => clienttranslate('In the next preparation choice, all players must choose Draw. Players who have already finished preparing must also draw, and Stop from other card effects is ignored.')],
                ],
            ],
            '055' => [
                'name' => clienttranslate('Black Hole Generator'),
                'effects' => [
                    ['timings' => [EffectTiming::OnAcquire], 'text' => clienttranslate('Choose up to 2 cards from among all your cards and remove them from the game.')],
                ],
            ],
            '056' => [
                'name' => clienttranslate('Unknown'),
                'effects' => [],
            ],
            '057' => [
                'name' => clienttranslate('Safe Passage Charm'),
                'effects' => [
                    ['timings' => [EffectTiming::PowerMod], 'text' => clienttranslate('In round 1, treat this card\'s {power} as 0.')],
                ],
            ],
            '058' => [
                'name' => clienttranslate('Misfortune-Warding Charm'),
                'effects' => [
                    ['timings' => [EffectTiming::OnAcquire], 'text' => clienttranslate('Choose up to 1 face-up card in your prep area and remove it from the game.')],
                ],
            ],
        ];
    }

    /**
     * @return array{name: string, effects: list<array{timings: list<EffectTiming>, text: string}>}|null
     */
    public static function get(string $cardNo): ?array
    {
        return self::all()[$cardNo] ?? null;
    }
}
