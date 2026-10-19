<?php

/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * MinimumJourney implementation : © <Your name here> <Your email address here>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Game.php
 *
 * This is the main file for your game logic.
 *
 * In this PHP file, you are going to defines the rules of the game.
 */

declare(strict_types=1);

namespace Bga\Games\MinimumJourney;

use Bga\GameFramework\UserException;
use Bga\Games\MinimumJourney\Data\BgaDatabase;
use Bga\Games\MinimumJourney\Data\BoardView;
use Bga\Games\MinimumJourney\Data\CardInfoView;
use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\PlayerValues;
use Bga\Games\MinimumJourney\Data\ResolveEntryRepository;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Flow\DrawPileView;
use Bga\Games\MinimumJourney\Flow\DrawResolver;
use Bga\Games\MinimumJourney\Flow\DrawRuleViolation;
use Bga\Games\MinimumJourney\Flow\ExploreOutputCalculator;
use Bga\Games\MinimumJourney\Flow\ExplorePhase;
use Bga\Games\MinimumJourney\Flow\FameTotals;
use Bga\Games\MinimumJourney\Flow\ExploreRuleViolation;
use Bga\Games\MinimumJourney\Flow\Notification;
use Bga\Games\MinimumJourney\Flow\PrepPhase;
use Bga\Games\MinimumJourney\Flow\PrepRuleViolation;
use Bga\Games\MinimumJourney\Flow\ReturnPhase;
use Bga\Games\MinimumJourney\Flow\RoundFlow;
use Bga\Games\MinimumJourney\Flow\StateId;
use Bga\Games\MinimumJourney\Setup\GameSetup;
use Bga\Games\MinimumJourney\Stats\BgaStatStore;
use Bga\Games\MinimumJourney\Stats\StatsRecorder;
use Bga\Games\MinimumJourney\States\RoundStart;

class Game extends \Bga\GameFramework\Table
{
    public CardRepository $cards;
    public GlobalValues $globalValues;
    public PlayerValues $playerValues;
    public ExploreOutputCalculator $outputCalculator;
    public PrepPhase $prepPhase;
    public ExplorePhase $explorePhase;
    public ReturnPhase $returnPhase;
    public DrawResolver $drawResolver;
    public StatsRecorder $stats;

    public function __construct()
    {
        parent::__construct();

        $this->stats = new StatsRecorder(new BgaStatStore($this->bga->tableStats, $this->bga->playerStats));

        $database = new BgaDatabase();
        $this->cards = new CardRepository($database);
        $this->globalValues = new GlobalValues($this->bga->globals);
        $this->playerValues = new PlayerValues($database);
        $this->outputCalculator = new ExploreOutputCalculator($this->cards, $this->globalValues, fn(): array => $this->mjSeatOrder());
        $this->prepPhase = new PrepPhase(
            $this->cards,
            $this->playerValues,
            $this->globalValues,
            fn(int $playerId): string => $this->getPlayerNameById($playerId),
            $this->outputCalculator,
        );
        $this->explorePhase = new ExplorePhase(
            $this->cards,
            $this->playerValues,
            $this->globalValues,
            $this->outputCalculator,
            fn(int $playerId): string => $this->getPlayerNameById($playerId),
        );
        $this->drawResolver = new DrawResolver(
            $this->cards,
            $this->playerValues,
            $this->globalValues,
            new ResolveEntryRepository($database),
            $this->outputCalculator,
            fn(int $playerId): string => $this->getPlayerNameById($playerId),
            fn(): array => $this->mjSeatOrder(),
        );
        $this->returnPhase = new ReturnPhase(
            $this->cards,
            $this->playerValues,
            $this->globalValues,
            fn(int $playerId): string => $this->getPlayerNameById($playerId),
        );
    }

    /**
     * 準備フェイズの処理が返した通知を送る（to が null なら全員、そうでなければ本人のみ）。
     *
     * @param list<Notification> $notifications
     */
    public function mjSendNotifications(array $notifications): void
    {
        $this->stats->recordNotifications($notifications, $this->globalValues->roundNo());
        foreach ($notifications as $n) {
            if ($n->to === null) {
                $this->bga->notify->all($n->name, $n->message, $n->args);
            } else {
                $this->bga->notify->player($n->to, $n->name, $n->message, $n->args);
            }
        }
        if ($this->mjIsFameShown() && FameTotals::affectedBy($notifications)) {
            $this->mjSendFameUpdate();
        }
        if ($notifications !== [] && $this->mjIsDrawPileViewable()) {
            $this->mjSendDrawPileUpdate();
        }
    }

    /**
     * ゲームオプション 102（自分の山札一覧）が有効か（007 §13.1）。
     */
    public function mjIsDrawPileViewable(): bool
    {
        return $this->bga->tableOptions->get(GameSetup::OPTION_VIEW_DRAW_PILE) === 2;
    }

    /**
     * N36 drawPileUpdate: 山札の一覧（カード番号順）を、その山札の持ち主にだけ送る。notify->all は観戦者に届くので使わない。
     * 通知を送るたびに全員ぶんを送る（山札が変わる契機が多く、取りこぼしを避けるため。007 §13.1）。
     */
    public function mjSendDrawPileUpdate(): void
    {
        foreach ($this->mjSeatOrder() as $playerId) {
            $this->bga->notify->player($playerId, 'drawPileUpdate', '', ['cards' => DrawPileView::cards($this->cards, $playerId)]);
        }
    }

    /**
     * ゲームオプション 101（名声合計の表示）が有効か（007 §13.1）。
     */
    public function mjIsFameShown(): bool
    {
        return $this->bga->tableOptions->get(GameSetup::OPTION_SHOW_FAME) === 2;
    }

    /**
     * N34 fameUpdate: 現在の名声合計を、卓の各プレイヤーにだけ送る。notify->all は観戦者に届くので使わない（007 §11.3）。
     */
    public function mjSendFameUpdate(): void
    {
        $seatOrder = $this->mjSeatOrder();
        $fame = (new FameTotals($this->cards, $this->globalValues))->totals($seatOrder);
        foreach ($seatOrder as $playerId) {
            $this->bga->notify->player($playerId, 'fameUpdate', '', ['fame' => $fame]);
        }
    }

    /**
     * 準備フェイズの規則違反を、翻訳つきのユーザー向けエラーにする。
     */
    public function mjPrepError(PrepRuleViolation $violation): UserException
    {
        $message = $violation->reason === PrepRuleViolation::EMPTY_DECK
            ? $this->_('You cannot draw because your deck is empty')
            : $this->_('You cannot choose now');
        return new UserException($message);
    }

    /**
     * 探査フェイズの規則違反を、翻訳つきのユーザー向けエラーにする。
     */
    public function mjExploreError(ExploreRuleViolation $violation): UserException
    {
        $message = match ($violation->reason) {
            ExploreRuleViolation::NOT_A_TARGET => $this->_('You cannot explore that card'),
            ExploreRuleViolation::ALREADY_EXPLORED => $this->_('You have already explored this turn'),
            default => $this->_('It is not your turn to explore'),
        };
        return new UserException($message);
    }

    /**
     * ドロー時効果の選択の規則違反を、翻訳つきのユーザー向けエラーにする。
     */
    public function mjDrawError(DrawRuleViolation $violation): UserException
    {
        $message = match ($violation->reason) {
            DrawRuleViolation::INVALID_TARGET => $this->_('You cannot choose that'),
            DrawRuleViolation::ALREADY_DECIDED => $this->_('You have already decided'),
            default => $this->_('You cannot choose now'),
        };
        return new UserException($message);
    }

    /**
     * 席順（player_no の昇順）のプレイヤー ID。
     *
     * @return list<int>
     */
    public function mjSeatOrder(): array
    {
        $ids = $this->getObjectListFromDB('SELECT `player_id` FROM `player` ORDER BY `player_no`', true);
        return array_map(static fn(mixed $id): int => (int) $id, array_values($ids));
    }

    /**
     * Compute and return the current game progression.
     *
     * The number returned must be an integer between 0 and 100.
     *
     * This method is called each time we are in a game state with the "updateGameProgression" property set to true.
     *
     * @return int
     */
    public function getGameProgression()
    {
        // 006 §8。探査山札の消費で測る
        $pile8 = $this->cards->count(Zone::ExploreDeck, 8);
        $total = 0;
        foreach (GameSetup::EXPLORE_COSTS as $cost) {
            $total += $this->cards->count(Zone::ExploreDeck, $cost);
        }

        return RoundFlow::progression(
            $this->globalValues->pile8Initial(),
            $pile8,
            $this->globalValues->pilesInitialTotal(),
            $total,
        );
    }

    /**
     * Migrate database.
     *
     * You don't have to care about this until your game has been published on BGA. Once your game is on BGA, this
     * method is called everytime the system detects a game running with your old database scheme. In this case, if you
     * change your database scheme, you just have to apply the needed changes in order to update the game database and
     * allow the game to continue to run with your new version.
     *
     * @param int $from_version
     * @return void
     */
    public function upgradeTableDb($from_version)
    {
//       if ($from_version <= 1404301345)
//       {
//            // ! important ! Use `DBPREFIX_<table_name>` for all tables
//
//            $sql = "ALTER TABLE `DBPREFIX_xxxxxxx` ....";
//            $this->applyDbUpgradeToAllDB( $sql );
//       }
//
//       if ($from_version <= 1405061421)
//       {
//            // ! important ! Use `DBPREFIX_<table_name>` for all tables
//
//            $sql = "CREATE TABLE `DBPREFIX_xxxxxxx` ....";
//            $this->applyDbUpgradeToAllDB( $sql );
//       }
    }

    /*
     * Gather all information about current game situation (visible by the current player).
     *
     * The method is called each time the game interface is displayed to a player, i.e.:
     *
     * - when the game starts
     * - when a player refreshes the game page (F5)
     */
    protected function getAllDatas(int $currentPlayerId): array
    {
        // 閲覧者に見せてよい情報だけを返す（山札・探査山札は枚数のみ。007 §8）
        $result = [];

        $result["players"] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score` FROM `player`"
        );

        $board = (new BoardView($this->cards, $this->globalValues, $this->playerValues, $this->outputCalculator))->build($this->mjSeatOrder());

        // 名声合計は 101 が有効なときだけ、卓のプレイヤーにだけ返す（観戦者には返さない。007 §11.3）
        $seatOrder = $this->mjSeatOrder();
        if ($this->mjIsFameShown() && in_array($currentPlayerId, $seatOrder, true)) {
            $fame = (new FameTotals($this->cards, $this->globalValues))->totals($seatOrder);
            foreach ($fame as $playerId => $total) {
                $board['players'][$playerId]['fame'] = $total;
            }
        }

        // ツールチップ用の静的なカード情報と、ゲームオプション（007 §8.2）
        $result['cardInfo'] = CardInfoView::build(fn(string $text): string => $this->_($text));
        $result['options'] = [
            'charmSide' => $this->bga->tableOptions->get(GameSetup::OPTION_CHARM_SIDE),
            'showFame' => $this->bga->tableOptions->get(GameSetup::OPTION_SHOW_FAME),
            'viewDrawPile' => $this->bga->tableOptions->get(GameSetup::OPTION_VIEW_DRAW_PILE),
        ];

        // 山札の一覧は 102 が有効なとき、卓のプレイヤー本人の分だけ返す（観戦者・他のプレイヤーには返さない。007 §13.1）
        if ($this->mjIsDrawPileViewable() && in_array($currentPlayerId, $seatOrder, true)) {
            $result['myDrawPile'] = DrawPileView::cards($this->cards, $currentPlayerId);
        }

        // ゲーム終了後（状態 99。再読み込み・リプレイ）だけ、全員のデッキ全体の券面を公開する（007 §8.4）
        if ($this->gamestate->getCurrentMainStateId() === StateId::GAME_END) {
            $finalDecks = [];
            $fameTotals = new FameTotals($this->cards, $this->globalValues);
            foreach ($seatOrder as $playerId) {
                $finalDecks[$playerId] = $fameTotals->deckCards($playerId);
            }
            $result['finalDecks'] = $finalDecks;
            // 同点処理の内訳（未確認帯域と各シンボルの枚数。001 §9-3、作者指示 2026-10-19）
            $result['finalSummaries'] = $fameTotals->tieBreakSummaries($seatOrder);
        }

        return array_replace_recursive($result, $board);
    }

    /**
     * This method is called only once, when a new game is launched. In this method, you must setup the game
     *  according to the game rules, so that the game is ready to be played.
     */
    protected function setupNewGame($players, $options = [])
    {
        // Set the colors of the players with HTML color code. The default below is red/green/blue/orange/brown. The
        // number of colors defined here must correspond to the maximum number of players allowed for the gams.
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $query_values = [];
        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"]),
            ]);
        }

        // Create players based on generic information.
        //
        // NOTE: You can add extra field on player table in the database (see dbmodel.sql) and initialize
        // additional fields directly here.
        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // ゲームの準備（001 §3、007 §5.3）。BGA に依存しない部分は GameSetup にある。
        // グローバル値の初期化、カードの作成とシャッフル、列の公開、お守りの面と初期所持者もここで行う。
        (new GameSetup($this->cards, $this->globalValues))->run(
            array_keys($players),
            $this->bga->tableOptions->get(GameSetup::OPTION_CHARM_SIDE),
        );

        // 統計の初期化（007 §12。定義は stats.jsonc）
        $this->stats->init();

        // 最初の状態は GAME 状態のラウンド開始（006 §2.1）
        return RoundStart::class;
    }

    /**
     * デバッグ用: 指定した状態へ飛ぶ（Studio の Debug ボタンから）。
     */
    public function debug_goToState(int $state = StateId::PREP_STEP_START)
    {
        $this->gamestate->jumpToState($state);
    }
}
