<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\Zone;

/**
 * 現在の名声合計（最終得点と同じ計算。007 §11.3）。ゲームオプション 101 が有効なときの表示に使う。
 * 山札の中身は通知や args に載せず、合計の数値だけを返す。
 */
final class FameTotals
{
    /** 名声が変わりうる通知（獲得・除外・お守りの移動・036 の入れ替え） */
    private const CHANGING = ['cardAcquired', 'cardsRemoved', 'amuletMoved', 'wormholeSwapped'];

    public function __construct(
        private readonly CardRepository $cards,
        private readonly GlobalValues $globals,
    ) {
    }

    /**
     * @param list<Notification> $notifications
     */
    public static function affectedBy(array $notifications): bool
    {
        foreach ($notifications as $n) {
            if (in_array($n->name, self::CHANGING, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<int> $seatOrder
     * @return array<int, int> プレイヤー ID => 名声合計
     */
    public function totals(array $seatOrder): array
    {
        $charm = $this->cards->list(Zone::Charm)[0] ?? null;
        $unknownAcquirer = $this->globals->unknownAcquirer();
        $result = [];
        foreach ($seatOrder as $playerId) {
            $result[$playerId] = FinalScorer::score(
                $this->deckCardNos($playerId),
                $charm !== null && $charm->locationArg === $playerId,
                $unknownAcquirer === $playerId,
            )->fame();
        }
        return $result;
    }

    /**
     * デッキ（山札・準備場・獲得・裏向き。付加カードも別の 1 枚として）のカード番号。除外されたカードとお守りは含まない。
     *
     * @return list<int>
     */
    public function deckCardNos(int $playerId): array
    {
        $nos = [];
        foreach ([Zone::Deck, Zone::Prep, Zone::Acquired, Zone::Facedown] as $zone) {
            foreach ($this->cards->list($zone, $playerId) as $card) {
                $nos[] = $card->cardNo;
                foreach ($this->cards->attachedTo($card) as $attached) {
                    $nos[] = $attached->cardNo;
                }
            }
        }
        return $nos;
    }
}
