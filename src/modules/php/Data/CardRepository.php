<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * card テーブルの読み書きを 1 か所に閉じ込めるクラス（仕様書 007 §3.5）。
 * 他のコードは card テーブルを直接読まず、このクラスだけを使う。
 *
 * 山（deck、explore_deck、facedown）の一覧を返す list() は、公開してはならない順序と中身を含む。
 * 呼び出し箇所をレビューの検査項目にする（007 §3.5）。
 * 自前 DAO で実装している理由は、docs/specs/007-data-model.md の「確認済み（I-02）」を参照。
 */
final class CardRepository
{
    /** 付加カードを付けられる 046 のカード番号 */
    public const ATTACH_HOST_CARD_NO = 46;

    private const COLUMNS = '`card_id`, `card_location`, `card_location_arg`, `card_order`, `card_no`, `card_art`, `card_sideways`';

    /** @var callable(int, int): int */
    private $rand;

    /**
     * @param callable(int, int): int|null $rand 乱数（両端を含む）。null なら bga_rand
     */
    public function __construct(private readonly Database $db, ?callable $rand = null)
    {
        $this->rand = $rand ?? static fn(int $min, int $max): int => bga_rand($min, $max);
    }

    // ---- 作成 ----

    /**
     * カードを 1 枚作る（setupNewGame 用）。
     */
    public function create(int $cardNo, int $art, Zone $zone, int $arg = 0, int $order = 0): Card
    {
        $this->db->execute(sprintf(
            "INSERT INTO `card` (`card_location`, `card_location_arg`, `card_order`, `card_no`, `card_art`) "
            . "VALUES ('%s', %d, %d, %d, %d)",
            $zone->value,
            $arg,
            $order,
            $cardNo,
            $art,
        ));
        return $this->get($this->db->lastInsertId());
    }

    // ---- 読み取り ----

    public function get(int $id): Card
    {
        $rows = $this->db->rows('SELECT ' . self::COLUMNS . ' FROM `card` WHERE `card_id` = ' . $id);
        if ($rows === []) {
            throw new \InvalidArgumentException("card not found: $id");
        }
        return Card::fromRow($rows[0]);
    }

    /**
     * @param list<int> $ids
     * @return array<int, Card> ID をキーにした連想配列
     */
    public function getMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->fetch('WHERE `card_id` IN (' . implode(',', array_map('intval', $ids)) . ')');
    }

    /**
     * カード番号で探す（同じ番号は卓内に 1 枚。絵柄違いの初期カードは複数）。
     *
     * @return list<Card>
     */
    public function findByCardNo(int $cardNo): array
    {
        return array_values($this->fetch('WHERE `card_no` = ' . $cardNo));
    }

    public function count(Zone $zone, ?int $arg = null): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM `card` WHERE ' . DeckMath::where($zone, $arg));
    }

    /**
     * 置き場所のカード一覧（order の昇順。同順位は ID の昇順）。山の一覧は公開しないこと。
     *
     * @return list<Card>
     */
    public function list(Zone $zone, ?int $arg = null): array
    {
        return array_values($this->fetch(
            'WHERE ' . DeckMath::where($zone, $arg) . ' ORDER BY `card_order` ASC, `card_id` ASC',
        ));
    }

    /**
     * 山の上から n 枚（動かさない）。先頭が一番上。
     *
     * @return list<Card>
     */
    public function peekTop(Zone $zone, ?int $arg, int $n): array
    {
        return array_values($this->fetch(
            'WHERE ' . DeckMath::where($zone, $arg) . ' ORDER BY `card_order` DESC, `card_id` DESC LIMIT ' . max(0, $n),
        ));
    }

    /**
     * 055 の解決中だけ本人に見せる、山札・準備場・獲得カード・facedown の一覧。
     * 山の順序を出さないよう card_no の昇順で返す（007 §3.5、§7.5）。付加カードも含む。
     *
     * @return list<Card>
     */
    public function deckViewForOwner(int $playerId): array
    {
        $cards = [];
        foreach ([Zone::Deck, Zone::Prep, Zone::Acquired, Zone::Facedown] as $zone) {
            foreach ($this->list($zone, $playerId) as $card) {
                $cards[] = $card;
                foreach ($this->attachedTo($card) as $attached) {
                    $cards[] = $attached;
                }
            }
        }
        usort($cards, static fn(Card $a, Card $b): int => [$a->cardNo, $a->art] <=> [$b->cardNo, $b->art]);
        return $cards;
    }

    /**
     * 列の空きスロット番号を小さい順に $count 個返す（帰還フェイズ③の補充用）。
     *
     * @return list<int>
     */
    public function rowFillSlots(int $cost, int $count): array
    {
        $occupied = array_map(
            static fn(Card $c): int => $c->order,
            $this->list(Zone::Row, $cost),
        );
        return DeckMath::freeSlots($occupied, $count);
    }

    /**
     * 付け先のカードに付いている付加カード。
     *
     * @return list<Card>
     */
    public function attachedTo(Card $host): array
    {
        if ($host->cardNo !== self::ATTACH_HOST_CARD_NO) {
            return [];
        }
        return $this->list(Zone::Attached, $host->id);
    }

    // ---- 移動 ----

    /**
     * 任意の置き場所へ動かす。order を省略すると、山は上に、それ以外は末尾（最大 + 1）に置く。
     * 046 を動かすと、付加カードも同じ置き場所へ一緒に動く（007 §3.4）。
     */
    public function move(Card $card, Zone $zone, int $arg = 0, ?int $order = null): void
    {
        $attached = $this->attachedTo($card);
        $order ??= $this->nextTopOrder($zone, $arg);
        $this->place($card->id, $zone, $arg, $order);
        foreach ($attached as $a) {
            // 付加カードは 046 と同じ order に置く（同順位は ID の昇順で並ぶ）
            $this->place($a->id, $zone, $arg, $order);
        }
    }

    /**
     * 山札の上から 1 枚を準備場の末尾へ（表向き）。山札が空なら null。
     */
    public function drawTopToPrep(int $playerId): ?Card
    {
        $top = $this->peekTop(Zone::Deck, $playerId, 1)[0] ?? null;
        if ($top === null) {
            return null;
        }
        $this->move($top, Zone::Prep, $playerId);
        return $this->get($top->id);
    }

    /**
     * 山札の上から 1 枚を facedown へ。山札が空なら null。戻り値の券面を通知に入れないこと。
     */
    public function drawTopToFacedown(int $playerId): ?Card
    {
        $top = $this->peekTop(Zone::Deck, $playerId, 1)[0] ?? null;
        if ($top === null) {
            return null;
        }
        $this->move($top, Zone::Facedown, $playerId);
        return $this->get($top->id);
    }

    public function putOnTop(Card $card, Zone $zone, int $arg = 0): void
    {
        $this->move($card, $zone, $arg, DeckMath::topOrder($this->extreme('MAX', $zone, $arg, $card->id)));
    }

    public function putOnBottom(Card $card, Zone $zone, int $arg = 0): void
    {
        $this->move($card, $zone, $arg, DeckMath::bottomOrder($this->extreme('MIN', $zone, $arg, $card->id)));
    }

    public function moveToAcquired(Card $card, int $playerId): void
    {
        $this->move($card, Zone::Acquired, $playerId);
    }

    /**
     * ゲームから除外する（046 を渡すと付加カードも一緒に）。
     *
     * @param list<Card> $cards
     */
    public function removeFromGame(array $cards): void
    {
        foreach ($cards as $card) {
            $this->move($card, Zone::Removed);
        }
    }

    /**
     * 公開済みのカードを山札に戻す。046 は付加カードを一緒に戻し、戻した後に付加カードを含め全員の ID を付け替える。
     * 戻り値は古い ID から新しい ID への対応（通知には古い ID だけを載せる）。
     *
     * @param list<Card> $cards
     * @return array<int, int>
     */
    public function returnToDeck(array $cards, int $playerId, bool $shuffle): array
    {
        $ids = [];
        foreach ($cards as $card) {
            $attached = $this->attachedTo($card);
            $this->move($card, Zone::Deck, $playerId);
            $ids[] = $card->id;
            foreach ($attached as $a) {
                $ids[] = $a->id;
            }
        }
        $map = $this->hideIds($this->getMany($ids));
        if ($shuffle) {
            $this->shuffleZone(Zone::Deck, $playerId);
        }
        return $map;
    }

    /**
     * 付加カードを 046 に付ける。
     */
    public function attach(Card $host, Card $card): void
    {
        $this->place($card->id, Zone::Attached, $host->id, 1);
    }

    /**
     * 付加カードを 046 から外し、指定の置き場所へ動かす（帰還フェイズ②）。
     */
    public function detach(Card $card, Zone $zone, int $arg = 0): void
    {
        $this->place($card->id, $zone, $arg, $this->nextTopOrder($zone, $arg));
    }

    public function setSideways(Card $card, bool $sideways): void
    {
        $this->db->execute(sprintf(
            'UPDATE `card` SET `card_sideways` = %d WHERE `card_id` = %d',
            $sideways ? 1 : 0,
            $card->id,
        ));
    }

    // ---- シャッフル・ID ----

    public function shuffleDeck(int $playerId): void
    {
        $this->shuffleZone(Zone::Deck, $playerId);
    }

    /**
     * 置き場所の order を 0 から振り直して並べ替える。結果の順序は通知にもログにも出さない。
     */
    public function shuffleZone(Zone $zone, int $arg = 0): void
    {
        $ids = array_map(static fn(Card $c): int => $c->id, $this->list($zone, $arg));
        $this->setOrders(DeckMath::shuffleList($ids, $this->rand));
    }

    /**
     * 指定した ID の順に order を 0, 1, 2, ... にする（初期の探査山札などの作成後の並び替え用）。
     *
     * @param list<int> $ids
     */
    public function setOrders(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $cases = [];
        foreach ($ids as $order => $id) {
            $cases[] = sprintf('WHEN %d THEN %d', $id, $order);
        }
        $this->db->execute(
            'UPDATE `card` SET `card_order` = CASE `card_id` ' . implode(' ', $cases) . ' END '
            . 'WHERE `card_id` IN (' . implode(',', array_map('intval', $ids)) . ')',
        );
    }

    /**
     * ItemManager の changeIds() 相当（007 §7.4）。渡したカードの ID を乱数で並べ替えた順に新しい ID へ付け替える。
     * 新しい ID は現在の最大値の次から振る。付加カード（location_arg が付け先の ID）の参照も更新する。
     * 戻り値は古い ID から新しい ID への対応。通知に載せない。
     *
     * @param list<Card>|array<int, Card> $cards
     * @return array<int, int>
     */
    public function hideIds(array $cards): array
    {
        $oldIds = array_map(static fn(Card $c): int => $c->id, array_values($cards));
        $oldIds = DeckMath::shuffleList($oldIds, $this->rand);
        $next = (int) $this->db->value('SELECT COALESCE(MAX(`card_id`), 0) FROM `card`') + 1;
        $map = [];
        foreach ($oldIds as $old) {
            $map[$old] = $next++;
        }
        foreach ($map as $old => $new) {
            $this->db->execute("UPDATE `card` SET `card_location_arg` = $new "
                . "WHERE `card_location` = 'attached' AND `card_location_arg` = $old");
            $this->db->execute("UPDATE `card` SET `card_id` = $new WHERE `card_id` = $old");
        }
        return $map;
    }

    // ---- 内部 ----

    /**
     * @return array<int, Card>
     */
    private function fetch(string $tail): array
    {
        $result = [];
        foreach ($this->db->rows('SELECT ' . self::COLUMNS . ' FROM `card` ' . $tail) as $row) {
            $card = Card::fromRow($row);
            $result[$card->id] = $card;
        }
        return $result;
    }

    private function place(int $id, Zone $zone, int $arg, int $order): void
    {
        $this->db->execute(sprintf(
            "UPDATE `card` SET `card_location` = '%s', `card_location_arg` = %d, `card_order` = %d, "
            . '`card_sideways` = 0 WHERE `card_id` = %d',
            $zone->value,
            $arg,
            $order,
            $id,
        ));
    }

    private function nextTopOrder(Zone $zone, int $arg): int
    {
        return DeckMath::topOrder($this->extreme('MAX', $zone, $arg, null));
    }

    /**
     * 置き場所の order の最大または最小（空なら null）。$exceptId のカードは除く。
     */
    private function extreme(string $fn, Zone $zone, int $arg, ?int $exceptId): ?int
    {
        $sql = "SELECT $fn(`card_order`) FROM `card` WHERE " . DeckMath::where($zone, $arg);
        if ($exceptId !== null) {
            $sql .= ' AND `card_id` <> ' . $exceptId;
        }
        $v = $this->db->value($sql);
        return $v === null ? null : (int) $v;
    }
}
