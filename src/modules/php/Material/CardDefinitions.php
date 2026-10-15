<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * 全 58 種のカードの静的な定義（仕様書 002 §4・§5、008 §2）。
 * 値は 002 の一覧表と 008 の効果記述子から転記している。
 */
final class CardDefinitions
{
    /** @var array<string, CardDefinition>|null カード番号 => 定義 */
    private static ?array $cache = null;

    /**
     * カード番号（'001'〜'058'）で定義を引く。存在しなければ null。
     */
    public static function get(string $cardNo): ?CardDefinition
    {
        return self::all()[$cardNo] ?? null;
    }

    /**
     * 英語識別子で定義を引く。存在しなければ null。
     */
    public static function getById(string $id): ?CardDefinition
    {
        foreach (self::all() as $card) {
            if ($card->id === $id) {
                return $card;
            }
        }
        return null;
    }

    /**
     * 全カード（カード番号の昇順、カード番号がキー）。
     *
     * @return array<string, CardDefinition>
     */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /**
     * 探査コスト別のカード。お守りは $cost = null で引く。
     *
     * @return list<CardDefinition>
     */
    public static function byCost(?int $cost): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CardDefinition $c): bool => $c->cost === $cost,
        ));
    }

    /**
     * 指定のシンボルを（複数のうち 1 つでも）持つカード。
     *
     * @return list<CardDefinition>
     */
    public static function bySymbol(Symbol $symbol): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CardDefinition $c): bool => $c->hasSymbol($symbol),
        ));
    }

    /**
     * 指定の区分のカード。
     *
     * @return list<CardDefinition>
     */
    public static function byCategory(CardCategory $category): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CardDefinition $c): bool => $c->category === $category,
        ));
    }

    /**
     * 指定のタイミングの効果を持つカード。
     *
     * @return list<CardDefinition>
     */
    public static function byTiming(EffectTiming $timing): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CardDefinition $c): bool => in_array($timing, $c->timings(), true),
        ));
    }

    /**
     * 物理的な総枚数（70 枚になる）。057 と 058 は両面 1 枚なので、お守りは合わせて 1 枚と数える。
     */
    public static function totalCount(): int
    {
        $total = 1; // お守り（057/058）
        foreach (self::all() as $c) {
            if ($c->category !== CardCategory::CharmCard) {
                $total += $c->count;
            }
        }
        return $total;
    }

    /**
     * @param list<Symbol> $symbols
     * @param list<EffectDefinition> $effects
     */
    private static function card(
        string $no,
        string $id,
        string $nameEn,
        string $nameJa,
        array $symbols,
        ?int $cost,
        int $power,
        int $fame,
        array $effects = [],
        int $count = 1,
    ): CardDefinition {
        $n = (int) $no;
        $category = match (true) {
            $n <= 3 => CardCategory::Starter,
            $n <= 55 => CardCategory::Drift,
            $n === 56 => CardCategory::UnknownBand,
            default => CardCategory::CharmCard,
        };
        // 絵柄の数: 001・002・003 は枚数と同じ（4・8・4）、それ以外は 1
        return new CardDefinition($no, $id, $nameEn, $nameJa, $category, $symbols, $cost, $power, $fame, $count, $count, $effects);
    }

    /**
     * @return array<string, CardDefinition>
     */
    private static function build(): array
    {
        $earth = [Symbol::Earth];
        $solar = [Symbol::SolarSystem];
        $milky = [Symbol::MilkyWay];
        $virgo = [Symbol::VirgoCluster];
        $lani = [Symbol::Laniakea];

        $draw = EffectTiming::OnDraw;
        $explore = EffectTiming::OnExplore;
        $during = EffectTiming::DuringExplore;
        $pmod = EffectTiming::PowerMod;
        $fmod = EffectTiming::FameMod;
        $acq = EffectTiming::OnAcquire;
        $own = PowerModStage::Own;

        $cards = [
            // 初期カード
            self::card('001', 'flag', 'Flag', '国旗', $earth, 0, 0, 3, [], 4),
            self::card('002', 'crew', 'Crew', '乗組員', $earth, 0, 1, 2, [], 8),
            self::card('003', 'starter_ship', 'Starter Ship', '初期探査船', $earth, 0, 2, 1, [], 4),

            // 探査コスト 2（太陽系）
            self::card('004', 'colony_camp', 'Colony Camp', 'コロニーキャンプ', $solar, 2, 2, 0, [
                new EffectDefinition($acq, EffectKind::AcquireExcludePrep, ['min' => 1, 'max' => 1], choice: ['state' => 45, 'action' => 'actExcludePrepCard']),
            ]),
            self::card('005', 'robot_arm', 'Robot Arm', 'ロボットアーム', $solar, 2, 2, 0, [
                new EffectDefinition($draw, EffectKind::DeckTopPeek, ['n' => 2, 'mode' => 'REORDER'], drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actArrangeTop']),
            ]),
            self::card('006', 'torn_map', 'Torn Map', '破れた地図', $solar, 2, 2, 0, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => -1, 'scope' => 'EXPLORE_FIELD', 'symbol_filter' => ['MILKY_WAY', 'LANIAKEA']]),
            ]),
            self::card('007', 'laser_sight', 'Laser Sight', 'レーザー照準器', $solar, 2, 2, 1, [
                new EffectDefinition($draw, EffectKind::LockRowCard, drawLevel: DrawLevel::P5Seq, choice: ['state' => 34, 'action' => 'actChooseLockedCard']),
                new EffectDefinition($during, EffectKind::LockRowCard),
            ]),
            self::card('008', 'observation_satellite', 'Observation Satellite', '観測衛星', $solar, 2, 2, 2, [
                new EffectDefinition($draw, EffectKind::CopyDrawEffect, drawLevel: DrawLevel::Copy, choice: ['state' => 32, 'action' => 'actChooseCopySource']),
            ]),
            self::card('009', 'capricious_drill', 'Capricious Drill', '気まぐれなドリル', $solar, 2, 2, 2, [
                new EffectDefinition($draw, EffectKind::ReturnAndRedraw, ['selector' => 'PREV_CARD'], optional: true, drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actYesNo']),
            ]),
            self::card('010', 'efficient_development_manual', 'Efficient Development Manual', '効率的な開発マニュアル', $solar, 2, 2, 2, [
                new EffectDefinition($draw, EffectKind::DeckTopPeek, ['n' => 1, 'mode' => 'TOP_OR_BOTTOM'], drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actTopOrBottom']),
            ]),
            self::card('011', 'wide_area_radar', 'Wide-Area Radar', '広範囲レーダー', $solar, 2, 2, 2, [
                new EffectDefinition($explore, EffectKind::AddPileTopToRow, optional: true, choice: ['state' => 42, 'action' => 'actUseOnExplore']),
            ]),
            self::card('012', 'towable_container', 'Towable Container', '牽引可能コンテナ', $solar, 2, 2, 2, [
                new EffectDefinition($during, EffectKind::AdditionalExplore, optional: true, choice: ['state' => 43, 'action' => 'actExplore']),
            ]),
            self::card('013', 'local_map', 'Local Map', '局所的な地図', $solar, 2, 2, 2, [
                new EffectDefinition($explore, EffectKind::CostModifier, ['amount' => -1, 'scope' => 'EXPLORE_FIELD', 'this_turn_only' => true], optional: true, choice: ['state' => 42, 'action' => 'actUseOnExplore']),
            ]),
            self::card('014', 'recycler', 'Recycler', 'リサイクル装置', $solar, 2, 2, 2, [
                new EffectDefinition($draw, EffectKind::ReturnAndRedraw, ['selector' => 'SYMBOL_CARDS', 'symbol' => 'EARTH'], optional: true, drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actYesNo']),
            ]),
            self::card('015', 'rover_probe', 'Rover Probe', 'ローバー探査機', $solar, 2, 2, 2, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => -2, 'scope' => 'EXPLORE_FIELD', 'condition' => 'MIN_OUTPUT']),
            ]),
            self::card('016', 'patriot_robot', 'Patriot Robot', '愛国ロボット', $solar, 2, 2, 2, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'HAS_CARD_IN_PREP', 'card_no' => '001', 'amount' => 3], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('017', 'relay_camera', 'Relay Camera', '中継カメラ', $solar, 2, 2, 2, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'HAS_CARD_BEFORE', 'card_no' => '002', 'amount' => 2], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('018', 'gamble_charger', 'Gamble Charger', 'ギャンブルチャージャー', $solar, 2, 0, 1, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'INDEX_IN_PREP'], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),

            // 探査コスト 4（天の川銀河）
            self::card('019', 'ore_mixer', 'Ore Mixer', '鉱石ミキサー', $milky, 4, 2, 2, [
                new EffectDefinition($pmod, EffectKind::PowerRewrite, powerModStage: PowerModStage::Rewrite, powerModTarget: PowerModTarget::Next, fameModExempt: true, absorbedBy: '048'),
            ]),
            self::card('020', 'solar_system_exploration_manual', 'Solar System Exploration Manual', '太陽系探査マニュアル', $milky, 4, 2, 3, [
                new EffectDefinition($draw, EffectKind::DeckSearchToTop, ['symbol' => 'SOLAR_SYSTEM'], optional: true, drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actPickFromDeck']),
            ]),
            self::card('021', 'virgo_cluster_map', 'Virgo Cluster Map', '乙女座銀河団の地図', $milky, 4, 2, 4, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => -1, 'scope' => 'EXPLORE_FIELD', 'symbol_filter' => ['VIRGO_CLUSTER']]),
            ]),
            self::card('022', 'small_satellite', 'Small Satellite', '小型衛星', $milky, 4, 2, 2, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'PREP_COUNT_LE_LEFT_NEIGHBOR', 'amount' => 1], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('023', 'fussy_control_stick', 'Fussy Control Stick', 'こだわりの操縦桿', $milky, 4, 2, 2, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'PREV_LACKS_SYMBOL', 'symbol' => 'SOLAR_SYSTEM', 'amount' => 1], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('024', 'overflowing_fuel', 'Overflowing Fuel', '溢れ出る燃料', $milky, 4, 3, 2, [
                new EffectDefinition($draw, EffectKind::ExtraPrepareFinished, drawLevel: DrawLevel::P3),
            ]),
            self::card('025', 'clouded_space_telescope', 'Clouded Space Telescope', '曇った宇宙望遠鏡', $milky, 4, 3, 3, [
                new EffectDefinition($during, EffectKind::ExploreBan, ['scope' => 'ROW', 'except' => 'UNKNOWN']),
            ]),
            self::card('026', 'oversized_engine', 'Oversized Engine', '大振りエンジン', $milky, 4, 3, 3, [
                new EffectDefinition($during, EffectKind::ExploreBan, ['scope' => 'EXPLORE_FIELD', 'symbols' => ['SOLAR_SYSTEM', 'MILKY_WAY']]),
            ]),
            self::card('027', 'nearly_expired_space_food', 'Nearly Expired Space Food', '賞味期限が近い宇宙食', $milky, 4, 3, 4, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => 1, 'scope' => 'EXPLORE_FIELD', 'condition' => 'LAST_IN_ORDER']),
            ]),
            self::card('028', 'memory_album', 'Memory Album', '思い出のアルバム', $milky, 4, 3, 4, [
                new EffectDefinition($draw, EffectKind::NextChoiceRestriction, ['mode' => 'DRAW_ONLY'], drawLevel: DrawLevel::P6),
            ]),
            self::card('029', 'safety_device', 'Safety Device', '安全装置', $milky, 4, 4, 2, [
                new EffectDefinition($draw, EffectKind::NextChoiceRestriction, ['mode' => 'STOP_ONLY'], drawLevel: DrawLevel::P6),
            ]),
            self::card('030', 'nuclear_thermal_booster', 'Nuclear Thermal Booster', '核熱ブースター', $milky, 4, 4, 3, [
                new EffectDefinition($pmod, EffectKind::PowerNegate, powerModStage: PowerModStage::Negate, powerModTarget: PowerModTarget::Prev, ignoresBlock: true),
            ]),
            self::card('031', 'unbreakable_mirror', 'Unbreakable Mirror', '割れない鏡', $milky, 4, 1, 3, [
                new EffectDefinition($pmod, EffectKind::PowerReference, powerModStage: PowerModStage::Reference, powerModTarget: PowerModTarget::Prev),
            ]),
            self::card('032', 'pedal_generator', 'Pedal Generator', '人力発電機', $milky, 4, 2, 0, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'COUNT_SYMBOL', 'symbol' => 'EARTH'], powerModStage: $own, powerModTarget: PowerModTarget::Own),
                new EffectDefinition($fmod, EffectKind::FameBonus, ['rule' => 'COUNT_SYMBOL', 'symbol' => 'EARTH'], readsDeck: true),
            ]),
            self::card('033', 'solar_power_plant', 'Solar Power Plant', '太陽光発電設備', $milky, 4, 2, 0, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'COUNT_SYMBOL', 'symbol' => 'SOLAR_SYSTEM'], powerModStage: $own, powerModTarget: PowerModTarget::Own),
                new EffectDefinition($fmod, EffectKind::FameBonus, ['rule' => 'COUNT_SYMBOL', 'symbol' => 'SOLAR_SYSTEM'], readsDeck: true),
            ]),

            // 探査コスト 6（乙女座銀河団）
            self::card('034', 'dust_vacuum', 'Dust Vacuum', '粉塵バキューマ', $virgo, 6, 3, 2, [
                new EffectDefinition($acq, EffectKind::FreeAcquire, choice: ['state' => 46, 'action' => 'actFreeAcquire']),
            ]),
            self::card('035', 'milky_way_exploration_manual', 'Milky Way Exploration Manual', '天の川銀河探査マニュアル', $virgo, 6, 3, 2, [
                new EffectDefinition($draw, EffectKind::DeckSearchToTop, ['symbol' => 'MILKY_WAY'], optional: true, drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actPickFromDeck']),
            ]),
            self::card('036', 'makeshift_wormhole', 'Makeshift Wormhole', '簡易ワームホール', $virgo, 6, 3, 3, [
                new EffectDefinition($draw, EffectKind::SwapRowWithDeckTop, optional: true, drawLevel: DrawLevel::P4, choice: ['state' => 33, 'action' => 'actWormhole']),
            ]),
            self::card('037', 'fluffy_bed', 'Fluffy Bed', 'ふかふかのベッド', $virgo, 6, 3, 3, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => -1, 'scope' => 'EXPLORE_FIELD', 'condition' => 'IS_LAST_PREPARED']),
            ]),
            self::card('038', 'rough_map', 'Rough Map', '大まかな地図', $virgo, 6, 3, 4, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => -1, 'scope' => 'EXPLORE_FIELD', 'condition' => 'FIRST_IN_ORDER']),
            ]),
            self::card('039', 'jamming_transmitter', 'Jamming Transmitter', '妨害電波発信機', $virgo, 6, 3, 4, [
                new EffectDefinition($draw, EffectKind::DrawSuppression, drawLevel: DrawLevel::P1, suppressRule: 'ALLOW_SYMBOL:VIRGO_CLUSTER'),
            ]),
            self::card('040', 'pioneers_beacon', "Pioneer's Beacon", '先駆者の道標', $virgo, 6, 3, 3, [
                new EffectDefinition($acq, EffectKind::MarkTopAfterShuffle, ['only_phase' => 'EXPLORE']),
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'IS_FIRST_PREPARED', 'amount' => 1], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('041', 'pretty_little_box', 'Pretty Little Box', '綺麗な小箱', $virgo, 6, 3, 3, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'HOLDS_CHARM', 'amount' => 1], powerModStage: $own, powerModTarget: PowerModTarget::Own),
                new EffectDefinition($fmod, EffectKind::FameBonus, ['rule' => 'HOLDS_CHARM', 'amount' => 1]),
            ]),
            self::card('042', 'upgrade_patch', 'Upgrade Patch', 'アップグレードパッチ', $virgo, 6, 3, 3, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'PREV_HAS_SYMBOL', 'symbols' => ['SOLAR_SYSTEM', 'MILKY_WAY'], 'amount' => 1], powerModStage: $own, powerModTarget: PowerModTarget::Own),
                new EffectDefinition($fmod, EffectKind::FameBonus, ['rule' => 'PAIR_SYMBOLS', 'symbols' => ['SOLAR_SYSTEM', 'MILKY_WAY']], readsDeck: true),
            ]),
            self::card('043', 'runaway_transfer_pod', 'Runaway Transfer Pod', '暴走転送ポッド', $virgo, 6, 4, 4, [
                new EffectDefinition($draw, EffectKind::ReturnDrawnCard, optional: true, drawLevel: DrawLevel::P2, choice: ['state' => 31, 'action' => 'actDecideReturn']),
            ]),
            self::card('044', 'knowledge_database', 'Knowledge Database', 'ナレッジデータベース', $virgo, 6, 0, 4, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'PREP_COUNT'], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('045', 'upgrade_parts', 'Upgrade Parts', 'アップグレードパーツ', $virgo, 6, 1, 0, [
                new EffectDefinition($pmod, EffectKind::PowerSelfBonus, ['rule' => 'KIND_COUNT'], powerModStage: $own, powerModTarget: PowerModTarget::Own),
                new EffectDefinition($fmod, EffectKind::FameBonus, ['rule' => 'KIND_COUNT'], readsDeck: true),
            ]),

            // 探査コスト 8（ラニアケア超銀河団）
            self::card('046', 'some_part', 'Some Part', '何かの部品', $lani, 8, 2, 4, [
                new EffectDefinition($draw, EffectKind::AttachCard, drawLevel: DrawLevel::P2A),
            ]),
            self::card('047', 'map_to_glory', 'Map to Glory', '栄光への地図', $lani, 8, 3, 2, [
                new EffectDefinition($during, EffectKind::CostModifier, ['amount' => -2, 'scope' => 'EXPLORE_FIELD', 'symbol_filter' => ['UNKNOWN']]),
                new EffectDefinition($fmod, EffectKind::FameBonus, ['rule' => 'HAS_CARD', 'card_no' => '056', 'amount' => 3], readsDeck: true),
            ]),
            self::card('048', 'gravity_inverter', 'Gravity Inverter', '重力反転装置', $lani, 8, 3, 3, [
                new EffectDefinition($pmod, EffectKind::PowerRewrite, powerModStage: PowerModStage::Rewrite, powerModTarget: PowerModTarget::All, fameModExempt: true),
            ]),
            self::card('049', 'time_stopper', 'Time Stopper', '時間停止装置', $lani, 8, 3, 4, [
                new EffectDefinition($draw, EffectKind::NextChoiceRestriction, ['mode' => 'LATE_CHOICE'], drawLevel: DrawLevel::P6, choice: ['state' => 23, 'action' => 'actSelectPrepLate']),
            ]),
            self::card('050', 'space_exploration_manual', 'Space Exploration Manual', '宇宙探査マニュアル', $lani, 8, 3, 4, [
                new EffectDefinition($draw, EffectKind::DeckSearchToTop, ['symbol' => null], optional: true, drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actPickFromDeck']),
            ]),
            self::card('051', 'zeus_material', 'Zeus Material', 'ゼウスマテリアル', [
                Symbol::Earth,
                Symbol::MilkyWay,
                Symbol::Laniakea,
                Symbol::VirgoCluster,
                Symbol::SolarSystem,
            ], 8, 4, 3),
            self::card('052', 'scales_of_fate', 'Scales of Fate', '運命の天秤', $lani, 8, 4, 4, [
                new EffectDefinition($draw, EffectKind::DeckTopPeek, ['n' => 2, 'mode' => 'PICK_TOP_REST_BOTTOM'], drawLevel: DrawLevel::P5Sim, choice: ['state' => 35, 'action' => 'actPickOne']),
            ]),
            self::card('053', 'superflare_trigger', 'Superflare Trigger', 'スーパーフレア誘発装置', $lani, 8, 4, 5, [
                new EffectDefinition($draw, EffectKind::DrawSuppression, drawLevel: DrawLevel::P1, suppressRule: 'ALLOW_SAME_OWNER'),
            ]),
            self::card('054', 'seed_of_the_big_bang', 'Seed of the Big Bang', 'ビッグバンの種', $lani, 8, 4, 6, [
                new EffectDefinition($draw, EffectKind::NextChoiceRestriction, ['mode' => 'FORCE_DRAW_ALL'], drawLevel: DrawLevel::P0, immuneToSuppression: true),
            ]),
            self::card('055', 'black_hole_generator', 'Black Hole Generator', 'ブラックホール発生機', $lani, 8, 4, 6, [
                new EffectDefinition($acq, EffectKind::ExcludeFromDeck, ['max' => 2], optional: true, choice: ['state' => 47, 'action' => 'actExcludeDeckCards'], readsDeck: true),
            ]),

            // 未確認帯域
            self::card('056', 'unknown', 'Unknown', 'Unknown', [Symbol::Unknown], 10, 10, 10),

            // お守り（探査コストなし）
            self::card('057', 'safe_passage_charm', 'Safe Passage Charm', '航路安全のお守り', [Symbol::Charm], null, 1, 1, [
                new EffectDefinition($pmod, EffectKind::CharmPower, ['zero_on_round' => 1], powerModStage: $own, powerModTarget: PowerModTarget::Own),
            ]),
            self::card('058', 'misfortune_warding_charm', 'Misfortune-Warding Charm', '厄除けのお守り', [Symbol::Charm], null, 0, 1, [
                new EffectDefinition($acq, EffectKind::AcquireExcludePrep, ['min' => 0, 'max' => 1], optional: true, choice: ['state' => 51, 'action' => 'actExcludePrepCard']),
            ]),
        ];

        $map = [];
        foreach ($cards as $card) {
            $map[$card->cardNo] = $card;
        }
        return $map;
    }
}
