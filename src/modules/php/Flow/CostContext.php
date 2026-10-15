<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * 探査中の効果による探査コストの補正と探査禁止（008 §4.2・§4.3）の純粋な部分。
 * 補正は全て加算し、下限は 0。シンボル条件は、列のカードと 056 には自身のシンボルで、
 * 探査山札の上には山札に対応するシンボルで判定する（008 N-2）。
 *
 * @phpstan-type Mod array{amount: int, symbols: list<Symbol>|null}
 * @phpstan-type Ban array{scope: string, symbols: list<Symbol>|null, except_unknown: bool}
 */
final class CostContext
{
    /**
     * @param list<Mod> $mods 補正（symbols が null なら全てに作用、あればそのいずれかを持つカードだけ）
     * @param list<Ban> $bans 探査禁止（scope は 'ROW' か 'EXPLORE_FIELD'）
     */
    public function __construct(
        private readonly array $mods = [],
        private readonly array $bans = [],
    ) {
    }

    /**
     * 探査山札のコストに対応するシンボル（001 §2）。
     */
    public static function pileSymbol(int $cost): Symbol
    {
        return match ($cost) {
            2 => Symbol::SolarSystem,
            4 => Symbol::MilkyWay,
            6 => Symbol::VirgoCluster,
            default => Symbol::Laniakea,
        };
    }

    /**
     * @param list<Symbol> $symbols 対象のシンボル
     */
    public function effective(int $printedCost, array $symbols): int
    {
        $total = $printedCost;
        foreach ($this->mods as $mod) {
            if ($mod['symbols'] === null || self::overlaps($mod['symbols'], $symbols)) {
                $total += $mod['amount'];
            }
        }
        return max(0, $total);
    }

    /**
     * 列のカードが探査禁止か（025: 列は禁止、026: 指定シンボルを持つカードは禁止）。
     *
     * @param list<Symbol> $symbols
     */
    public function rowBanned(array $symbols): bool
    {
        foreach ($this->bans as $ban) {
            if ($ban['symbols'] === null || self::overlaps($ban['symbols'], $symbols)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 未確認帯域（056）が探査禁止か。025 は列だけで 056 は禁止しない。026 は 056 のシンボルが対象外。
     */
    public function unknownBanned(): bool
    {
        foreach ($this->bans as $ban) {
            if ($ban['scope'] === 'EXPLORE_FIELD' && $ban['symbols'] !== null && in_array(Symbol::Unknown, $ban['symbols'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<Symbol> $a
     * @param list<Symbol> $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        foreach ($a as $symbol) {
            if (in_array($symbol, $b, true)) {
                return true;
            }
        }
        return false;
    }
}
