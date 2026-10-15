<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\EffectKind;
use Bga\Games\MinimumJourney\Material\EffectTiming;
use Bga\Games\MinimumJourney\Material\PowerModStage;
use Bga\Games\MinimumJourney\Material\PowerModTarget;
use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * 探査力変動の段階エンジン（011 §4 案 A、§6-1）。BGA・DB に依存しない純粋な関数。
 *
 * S1 書き換え（048、019）→ S2 無効化（030）→ S3 自身変動 → S4 参照（031）の順に、準備場の表向きのカードごとの
 * 探査力を求める。各カードの効果は CardDefinitions の EffectDefinition（stage・target・kind・params）から駆動する。
 * 046 の付加カードの探査力・名声・効果・シンボルは 046 に加える（011 未確定事項 No.3・No.4 の暫定案）。
 * 横向きのカードは枚数に数え、探査力・効果を無視する（B1）。
 */
final class PowerMod
{
    /** 045 が数えるシンボルの 5 種（お守り・未確認帯域は数えない）。 */
    private const KINDS = [
        Symbol::Earth,
        Symbol::SolarSystem,
        Symbol::MilkyWay,
        Symbol::VirgoCluster,
        Symbol::Laniakea,
    ];

    private function __construct()
    {
    }

    /**
     * 準備場のカードごとの探査力（探査力変動を反映した値。お守りは含まない）。
     *
     * @param list<PrepCardInput> $cards 準備した順（横向きは末尾。裏向きは含めない）
     * @param int $round ラウンド数（057 の 1 ラウンド目 0 はお守り側で扱うので、ここでは使わない）
     * @param bool $holdsCharm 自分がお守りを保持しているか（041）
     * @param int|null $leftNeighborCount 左隣のプレイヤーの準備場の枚数（022。不明なら null で 022 は加算しない）
     * @return list<int> $cards と同じ並びの探査力
     */
    public static function apply(array $cards, int $round, bool $holdsCharm = false, ?int $leftNeighborCount = null): array
    {
        unset($round);
        $nodes = array_map(self::node(...), $cards);
        $live = [];
        foreach ($nodes as $i => $node) {
            if (!$node->input->sideways) {
                $live[] = $i;
            }
        }

        self::rewrite($nodes, $live);
        self::negate($nodes, $live);
        self::ownChanges($nodes, $live, $holdsCharm, $leftNeighborCount);
        self::reference($nodes, $live);

        return array_map(static fn(PowerModNode $n): int => $n->input->sideways ? 0 : $n->power, $nodes);
    }

    private static function node(PrepCardInput $input): PowerModNode
    {
        $cardNos = [sprintf('%03d', $input->cardNo)];
        if ($input->attachedCardNo !== null) {
            $cardNos[] = sprintf('%03d', $input->attachedCardNo);
        }
        $symbols = [];
        $effects = [];
        $fame = 0;
        $power = $input->basePower();
        $hasFameMod = false;
        foreach ($cardNos as $k => $cardNo) {
            $definition = CardDefinitions::get($cardNo);
            if ($definition === null) {
                continue;
            }
            $symbols = [...$symbols, ...$definition->symbols];
            $fame += $definition->fame;
            if ($k > 0 && !$input->sideways) {
                $power += $definition->power;
            }
            foreach ($definition->effects as $effect) {
                if ($effect->timing === EffectTiming::FameMod) {
                    $hasFameMod = true;
                }
                $isPowerMod = $effect->timing === EffectTiming::PowerMod && $effect->powerModStage !== null;
                if ($isPowerMod && $effect->kind !== EffectKind::CharmPower) {
                    $effects[] = $effect;
                }
            }
        }
        return new PowerModNode($input, $cardNos, $symbols, $effects, $fame, $hasFameMod, $power, !$input->sideways);
    }

    /**
     * S1 書き換え: 対象の探査力を名声にし、その探査力変動効果を発動させない。包含される効果（019 ← 048）は処理しない。
     * 名声変動を持つカードには作用しない（fameModExempt）。
     *
     * @param list<PowerModNode> $nodes
     * @param list<int> $live 横向きを除いた並びのインデックス
     */
    private static function rewrite(array $nodes, array $live): void
    {
        $targets = [];
        foreach ($live as $i) {
            foreach ($nodes[$i]->effects as $effect) {
                if ($effect->powerModStage !== PowerModStage::Rewrite) {
                    continue;
                }
                if ($effect->absorbedBy !== null && self::liveHas($nodes, $live, $effect->absorbedBy)) {
                    continue;
                }
                $range = match ($effect->powerModTarget) {
                    PowerModTarget::All => $live,
                    PowerModTarget::Next => isset($nodes[$i + 1]) && !$nodes[$i + 1]->input->sideways ? [$i + 1] : [],
                    default => [],
                };
                foreach ($range as $t) {
                    if ($effect->fameModExempt && $nodes[$t]->hasFameMod) {
                        continue;
                    }
                    $targets[$t] = true;
                }
            }
        }
        foreach (array_keys($targets) as $t) {
            $nodes[$t]->power = $nodes[$t]->fame;
            $nodes[$t]->active = false;
        }
    }

    /**
     * S2 無効化: 直前の表向きのカードの探査力を 0 にし、効果を発動させない。S1 の結果は上書きする（ignoresBlock）。
     *
     * @param list<PowerModNode> $nodes
     * @param list<int> $live
     */
    private static function negate(array $nodes, array $live): void
    {
        foreach ($live as $pos => $i) {
            foreach ($nodes[$i]->effects as $effect) {
                if ($effect->powerModStage !== PowerModStage::Negate || $effect->powerModTarget !== PowerModTarget::Prev) {
                    continue;
                }
                if ($pos === 0) {
                    continue;
                }
                $prev = $nodes[$live[$pos - 1]];
                if ($effect->fameModExempt && $prev->hasFameMod) {
                    continue;
                }
                $prev->power = 0;
                $prev->active = false;
            }
        }
    }

    /**
     * S3 自身変動: 発動する各カードを準備場の左から順に評価する。
     *
     * @param list<PowerModNode> $nodes
     * @param list<int> $live
     */
    private static function ownChanges(array $nodes, array $live, bool $holdsCharm, ?int $leftNeighborCount): void
    {
        foreach ($live as $pos => $i) {
            $node = $nodes[$i];
            if (!$node->active) {
                continue;
            }
            foreach ($node->effects as $effect) {
                if ($effect->powerModStage !== PowerModStage::Own || $effect->kind !== EffectKind::PowerSelfBonus) {
                    continue;
                }
                $prev = $pos > 0 ? $nodes[$live[$pos - 1]] : null;
                $node->power += self::bonus($effect->params, $nodes, $live, $pos, $prev, $holdsCharm, $leftNeighborCount);
            }
        }
    }

    /**
     * S4 参照: 直前の表向きのカードの S1〜S3 後の探査力を自身に加える。1 枚目なら +1。
     *
     * @param list<PowerModNode> $nodes
     * @param list<int> $live
     */
    private static function reference(array $nodes, array $live): void
    {
        foreach ($live as $pos => $i) {
            $node = $nodes[$i];
            if (!$node->active) {
                continue;
            }
            foreach ($node->effects as $effect) {
                if ($effect->powerModStage === PowerModStage::Reference && $effect->kind === EffectKind::PowerReference) {
                    $node->power += $pos > 0 ? $nodes[$live[$pos - 1]]->power : 1;
                }
            }
        }
    }

    /**
     * POWER_SELF_BONUS の加算量（008 §1.6 の rule）。枚数・種類は横向きを数え、付加カードのシンボルは 046 のシンボルとして数える。
     *
     * @param array<string, mixed> $params
     * @param list<PowerModNode> $nodes
     * @param list<int> $live
     */
    private static function bonus(
        array $params,
        array $nodes,
        array $live,
        int $pos,
        ?PowerModNode $prev,
        bool $holdsCharm,
        ?int $leftNeighborCount,
    ): int {
        $amount = (int) ($params['amount'] ?? 1);
        $rule = (string) ($params['rule'] ?? '');
        $count = count($nodes);
        switch ($rule) {
            case 'HAS_CARD_IN_PREP':
                // 付加カードのカード種別は使わない（008 N-4 (a)）ので、先頭（本体）の番号だけを見る
                return self::anyHost($nodes, (string) $params['card_no']) ? $amount : 0;
            case 'HAS_CARD_BEFORE':
                for ($k = 0; $k < $pos; $k++) {
                    if ($nodes[$live[$k]]->cardNos[0] === (string) $params['card_no']) {
                        return $amount;
                    }
                }
                return 0;
            case 'INDEX_IN_PREP':
                return $live[$pos] + 1;
            case 'PREP_COUNT_LE_LEFT_NEIGHBOR':
                return $leftNeighborCount !== null && $count <= $leftNeighborCount ? $amount : 0;
            case 'PREV_LACKS_SYMBOL':
                return $prev === null || !$prev->hasSymbol(Symbol::from((string) $params['symbol'])) ? $amount : 0;
            case 'PREV_HAS_SYMBOL':
                foreach ((array) $params['symbols'] as $symbol) {
                    if ($prev !== null && $prev->hasSymbol(Symbol::from((string) $symbol))) {
                        return $amount;
                    }
                }
                return 0;
            case 'COUNT_SYMBOL':
                $symbol = Symbol::from((string) $params['symbol']);
                return $amount * count(array_filter($nodes, static fn(PowerModNode $n): bool => $n->hasSymbol($symbol)));
            case 'IS_FIRST_PREPARED':
                return $pos === 0 ? $amount : 0;
            case 'HOLDS_CHARM':
                return $holdsCharm ? $amount : 0;
            case 'PREP_COUNT':
                return $amount * $count;
            case 'KIND_COUNT':
                $kinds = 0;
                foreach (self::KINDS as $symbol) {
                    foreach ($nodes as $n) {
                        if ($n->hasSymbol($symbol)) {
                            $kinds++;
                            break;
                        }
                    }
                }
                return $amount * $kinds;
            default:
                return 0;
        }
    }

    /**
     * @param list<PowerModNode> $nodes
     */
    private static function anyHost(array $nodes, string $cardNo): bool
    {
        foreach ($nodes as $n) {
            if ($n->cardNos[0] === $cardNo) {
                return true;
            }
        }
        return false;
    }

    /**
     * 表向き（横向きを除く）のカードに、自身または付加カードとして cardNo があるか。
     *
     * @param list<PowerModNode> $nodes
     * @param list<int> $live
     */
    private static function liveHas(array $nodes, array $live, string $cardNo): bool
    {
        foreach ($live as $i) {
            if (in_array($cardNo, $nodes[$i]->cardNos, true)) {
                return true;
            }
        }
        return false;
    }
}
