<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Setup;

use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\DeckMath;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;

/**
 * ゲームの準備（仕様書 001 §3、007 §5.3）。BGA の Table に依存しない部分を切り出したクラス。
 * Game::setupNewGame が席順のプレイヤー ID とオプション値を渡して run() を呼ぶ。
 * テストでは CardRepository と GlobalValues を差し替えて直接呼ぶ。
 */
final class GameSetup
{
    /** ゲームオプション 100: お守りの面（007 §13.1） */
    public const OPTION_CHARM_SIDE = 100;
    /** ゲームオプション 101: 名声合計の表示（007 §13.1）。セットアップでは使わない */
    public const OPTION_SHOW_FAME = 101;

    public const CHARM_SAFE_PASSAGE = 1;
    public const CHARM_MISFORTUNE_WARDING = 2;

    public const CARD_FLAG = 1;
    public const CARD_CREW = 2;
    public const CARD_STARTER_SHIP = 3;
    public const CARD_UNKNOWN_BAND = 56;
    public const CARD_SAFE_PASSAGE_CHARM = 57;
    public const CARD_MISFORTUNE_WARDING_CHARM = 58;

    /** 探査山札のコスト（小さい順） */
    public const EXPLORE_COSTS = [2, 4, 6, 8];

    /** 3 人プレイで各探査山札から伏せたまま除外する枚数（001 §3-2） */
    public const SETUP_REMOVED_PER_PILE = 2;

    /**
     * 探査場の列の規定枚数（人数 => コスト => 枚数。001 §3-3）。
     */
    public const ROW_SIZES = [
        3 => [2 => 3, 4 => 3, 6 => 2, 8 => 2],
        4 => [2 => 4, 4 => 3, 6 => 2, 8 => 2],
    ];

    /** @var callable(int, int): int */
    private $rand;

    /**
     * @param callable(int, int): int|null $rand 乱数（両端を含む）。null なら bga_rand
     */
    public function __construct(
        private readonly CardRepository $cards,
        private readonly GlobalValues $globals,
        ?callable $rand = null,
    ) {
        $this->rand = $rand ?? static fn(int $min, int $max): int => bga_rand($min, $max);
    }

    /**
     * ゲームオプション 100 の値（null なら既定）からお守りのカード番号を返す。
     */
    public static function charmCardNo(?int $optionValue): int
    {
        return $optionValue === self::CHARM_MISFORTUNE_WARDING
            ? self::CARD_MISFORTUNE_WARDING_CHARM
            : self::CARD_SAFE_PASSAGE_CHARM;
    }

    /**
     * 準備を行う。card テーブルとグローバル値が空であることを前提にする。
     *
     * @param list<int> $playerIds 席順（player_no の昇順）のプレイヤー ID
     * @param int|null $charmOption ゲームオプション 100 の値。null なら既定（航路安全）
     */
    public function run(array $playerIds, ?int $charmOption = null): void
    {
        $count = count($playerIds);
        if (!isset(self::ROW_SIZES[$count])) {
            throw new \InvalidArgumentException("unsupported number of players: $count");
        }

        $this->globals->initialize();
        $this->createStarterDecks($playerIds);
        $this->createExploreDecks($count);
        $this->createUnknownBandAndCharm($playerIds, self::charmCardNo($charmOption));

        // 作成順と ID の相関を消す（山の中身を ID から推測させない。007 §7.4）
        $all = [];
        foreach (Zone::cases() as $zone) {
            array_push($all, ...$this->cards->list($zone));
        }
        $this->cards->hideIds($all);
    }

    /**
     * 初期デッキ（国旗 1・乗組員 2・初期探査船 1）を配り、絵柄を割り当て、山札をシャッフルする。
     * 絵柄は種類ごとに 1〜artCount をシャッフルして席順に先頭から割り当てる（007 §5.3）。
     *
     * @param list<int> $playerIds
     */
    private function createStarterDecks(array $playerIds): void
    {
        $flagArts = $this->shuffledArts(self::CARD_FLAG);
        $crewArts = $this->shuffledArts(self::CARD_CREW);
        $shipArts = $this->shuffledArts(self::CARD_STARTER_SHIP);

        foreach ($playerIds as $seat => $playerId) {
            $this->cards->create(self::CARD_FLAG, $flagArts[$seat], Zone::Deck, $playerId);
            $this->cards->create(self::CARD_CREW, $crewArts[$seat * 2], Zone::Deck, $playerId);
            $this->cards->create(self::CARD_CREW, $crewArts[$seat * 2 + 1], Zone::Deck, $playerId);
            $this->cards->create(self::CARD_STARTER_SHIP, $shipArts[$seat], Zone::Deck, $playerId);
            $this->cards->shuffleDeck($playerId);
        }
    }

    /**
     * 漂流カードをコスト別の探査山札にし、3 人なら 2 枚ずつ伏せて除外し、列を公開する。
     */
    private function createExploreDecks(int $playerCount): void
    {
        $pile8 = 0;
        $total = 0;
        foreach (self::EXPLORE_COSTS as $cost) {
            foreach (CardDefinitions::byCost($cost) as $definition) {
                $this->cards->create((int) $definition->cardNo, 0, Zone::ExploreDeck, $cost);
            }
            $this->cards->shuffleZone(Zone::ExploreDeck, $cost);

            if ($playerCount === 3) {
                foreach ($this->cards->peekTop(Zone::ExploreDeck, $cost, self::SETUP_REMOVED_PER_PILE) as $card) {
                    $this->cards->move($card, Zone::SetupRemoved, 0, 0);
                }
            }

            $rowSize = self::ROW_SIZES[$playerCount][$cost];
            foreach ($this->cards->peekTop(Zone::ExploreDeck, $cost, $rowSize) as $slot => $card) {
                $this->cards->move($card, Zone::Row, $cost, $slot);
            }

            $remaining = $this->cards->count(Zone::ExploreDeck, $cost);
            $total += $remaining;
            if ($cost === 8) {
                $pile8 = $remaining;
            }
        }
        $this->globals->setPile8Initial($pile8);
        $this->globals->setPilesInitialTotal($total);
    }

    /**
     * 未確認帯域（056）を探査場に置き、選ばれた面のお守りを初期所持者（ランダム）に渡す。
     * 獲得時効果は発動しない（001 Q-4）。
     *
     * @param list<int> $playerIds
     */
    private function createUnknownBandAndCharm(array $playerIds, int $charmCardNo): void
    {
        $this->cards->create(self::CARD_UNKNOWN_BAND, 0, Zone::Unknown);
        $holder = $playerIds[($this->rand)(0, count($playerIds) - 1)];
        $this->cards->create($charmCardNo, 0, Zone::Charm, $holder);
    }

    /**
     * 絵柄番号 1〜artCount をシャッフルしたもの。
     *
     * @return list<int>
     */
    private function shuffledArts(int $cardNo): array
    {
        $definition = CardDefinitions::get(sprintf('%03d', $cardNo));
        if ($definition === null) {
            throw new \LogicException("card definition not found: $cardNo");
        }
        return DeckMath::shuffleList(range(1, $definition->artCount), $this->rand);
    }
}
