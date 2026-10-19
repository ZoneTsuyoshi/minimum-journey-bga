<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\Zone;

/**
 * 山札の一覧（ゲームオプション 102。仕様書 007 §13.1）。山札の持ち主にだけ見せる。
 * カード番号の昇順（同じ番号は ID の昇順）に並べ、山札の順序が漏れないようにする。
 * ストップで引いた裏向きの山（Zone::Facedown）は山札ではないので含めない（引いた本人にも見せない。007 §8.1）。
 */
final class DrawPileView
{
    /**
     * @return list<array{id: int, cardNo: int, art: int}>
     */
    public static function cards(CardRepository $cards, int $playerId): array
    {
        $views = [];
        foreach ($cards->list(Zone::Deck, $playerId) as $card) {
            $views[] = ['id' => $card->id, 'cardNo' => $card->cardNo, 'art' => $card->art];
        }
        usort($views, static fn(array $a, array $b): int => [$a['cardNo'], $a['id']] <=> [$b['cardNo'], $b['id']]);
        return $views;
    }
}
