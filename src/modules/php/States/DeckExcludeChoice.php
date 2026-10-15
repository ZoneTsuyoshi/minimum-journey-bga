<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\States;

use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Game;

/**
 * 055 ブラックホール発生機：自分のデッキから最大 2 枚を除外する（0 枚可）。
 * 「デッキ」を参照する効果なので、解決中だけ本人にデッキ全体の券面を _private で送る（006 §9.1、007 §7.5）。
 */
class DeckExcludeChoice extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: StateId::DECK_EXCLUDE_CHOICE,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} may exclude up to 2 cards from their deck'),
            descriptionMyTurn: clienttranslate('${you} may exclude up to 2 cards from your deck'),
            transitions: [
                'done' => StateId::ACQUIRE_DISPATCH,
            ],
        );
    }

    /**
     * 公開部分には候補を含めない（枚数から山札と別置き場の合計が分かるため）。券面は手番の本人だけ。DB は書かない。
     * リプレイ・観戦には届かない（_private は本人のみ）。
     *
     * @return array<string, mixed>
     */
    public function getArgs(int $activePlayerId): array
    {
        return [
            'max' => 2,
            '_private' => [$activePlayerId => ['deckCards' => $this->game->explorePhase->acquirePhase()->deckViewFor($activePlayerId)]],
        ];
    }

    /**
     * 0 枚（[Do not exclude]）のときは空配列が送信で落ちて未指定になるため、null も空配列として扱う。
     * 件数の上限は属性（max: 2）とルール側（excludeDeck）の両方で検証する。
     *
     * @param list<int>|null $cardIds 0〜2 件
     */
    #[PossibleAction]
    public function actExcludeDeckCards(#[IntArrayParam(max: 2)] ?array $cardIds, int $activePlayerId): string
    {
        $cardIds ??= [];
        try {
            $result = $this->game->explorePhase->acquirePhase()->excludeDeck($activePlayerId, array_map('intval', $cardIds));
        } catch (ExploreRuleViolation $violation) {
            throw $this->game->mjExploreError($violation);
        }
        $this->game->giveExtraTime($activePlayerId);
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }

    /**
     * 離脱したプレイヤーは除外しない（006 §7）。
     */
    public function zombie(int $playerId): string
    {
        $result = $this->game->explorePhase->acquirePhase()->excludeDeck($playerId, []);
        $this->game->mjSendNotifications($result->notifications);

        return (string) $result->transition;
    }
}
