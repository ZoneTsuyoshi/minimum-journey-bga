<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\Symbol;

/**
 * 名声の計算と同点処理の符号化（001 §9、008 §3.2、007 §11.2）の純粋な部分。
 * 名声 ＝ デッキのカードの印字の名声の合計 ＋ 名声変動 ＋ お守りの所持者の +1。
 * お守りの印字の名声は足さない（合計 +1 のみ。Q-22）。
 */
final class FinalScorer
{
    /** 同点処理の各シンボルの枚数の上限（007 §11.2 の符号化の桁） */
    private const SYMBOL_CAP = 99;

    private function __construct()
    {
    }

    /**
     * @param list<int> $deckCardNos デッキのカード番号（山札・準備場・獲得・裏向き・付加カード。除外とお守りを含まない）
     * @param bool $isCharmHolder 帰還フェイズ①の移動後のお守りの所持者か
     * @param bool $unknownAcquired 未確認帯域の獲得者か
     * @param int|null $modifier 名声変動の合計。null なら FameModifiers から計算する
     */
    public static function score(array $deckCardNos, bool $isCharmHolder, bool $unknownAcquired, ?int $modifier = null): FinalScore
    {
        $symbols = self::symbolCounts($deckCardNos);
        return new FinalScore(
            self::baseFame($deckCardNos),
            $modifier ?? FameModifiers::total($deckCardNos, $isCharmHolder),
            $isCharmHolder ? 1 : 0,
            $unknownAcquired,
            $symbols,
            self::aux($unknownAcquired, $symbols),
        );
    }

    /**
     * デッキのカードの印字の名声の合計。お守りのカードが混ざっていても足さない。
     *
     * @param list<int> $cardNos
     */
    public static function baseFame(array $cardNos): int
    {
        $sum = 0;
        foreach ($cardNos as $cardNo) {
            $definition = CardDefinitions::get(sprintf('%03d', $cardNo));
            if ($definition !== null && !$definition->hasSymbol(Symbol::Charm)) {
                $sum += $definition->fame;
            }
        }
        return $sum;
    }

    /**
     * 同点処理に使う 4 シンボルを持つカードの枚数。複数シンボルのカードは各シンボルで 1 枚と数える。
     *
     * @param list<int> $cardNos
     * @return array{laniakea: int, virgo: int, milkyWay: int, solar: int}
     */
    public static function symbolCounts(array $cardNos): array
    {
        $counts = ['laniakea' => 0, 'virgo' => 0, 'milkyWay' => 0, 'solar' => 0];
        $keys = [
            'laniakea' => Symbol::Laniakea,
            'virgo' => Symbol::VirgoCluster,
            'milkyWay' => Symbol::MilkyWay,
            'solar' => Symbol::SolarSystem,
        ];
        foreach ($cardNos as $cardNo) {
            $definition = CardDefinitions::get(sprintf('%03d', $cardNo));
            if ($definition === null) {
                continue;
            }
            foreach ($keys as $key => $symbol) {
                if ($definition->hasSymbol($symbol)) {
                    $counts[$key]++;
                }
            }
        }
        return $counts;
    }

    /**
     * 同点処理の値（007 §11.2）。大きいほど上位。
     * 未確認帯域の獲得 → ラニアケア少 → 乙女座少 → 天の川少 → 太陽系少。
     *
     * @param array{laniakea: int, virgo: int, milkyWay: int, solar: int} $symbols
     */
    public static function aux(bool $unknownAcquired, array $symbols): int
    {
        $cap = static fn(int $n): int => self::SYMBOL_CAP - min($n, self::SYMBOL_CAP);
        return ($unknownAcquired ? 100000000 : 0)
            + $cap($symbols['laniakea']) * 1000000
            + $cap($symbols['virgo']) * 10000
            + $cap($symbols['milkyWay']) * 100
            + $cap($symbols['solar']);
    }
}
