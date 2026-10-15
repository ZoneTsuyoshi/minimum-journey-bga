<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Flow;

use Bga\Games\MinimumJourney\Data\CardRepository;
use Bga\Games\MinimumJourney\Data\GlobalValues;
use Bga\Games\MinimumJourney\Data\Zone;
use Bga\Games\MinimumJourney\Material\CardDefinitions;
use Bga\Games\MinimumJourney\Material\EffectKind;

/**
 * 探査出力の計算（011 §6、001 §6-1）のうち DB を読む部分。
 * 準備場の表向きのカード（横向きを含む。裏向きは別置き場にあるので含まれない）→ 探査力変動（PowerMod）→
 * 合計 → お守りの探査力 → 半減 → 下限 0。
 */
final class ExploreOutputCalculator
{
    public function __construct(
        private readonly CardRepository $cards,
        private readonly GlobalValues $globals,
    ) {
    }

    /**
     * 準備場の探査力の合計（探査力変動を反映。お守りと半減の前）。
     */
    public function total(int $playerId): int
    {
        $inputs = [];
        foreach ($this->cards->list(Zone::Prep, $playerId) as $card) {
            $definition = CardDefinitions::get(sprintf('%03d', $card->cardNo));
            $inputs[] = new PrepCardInput($card->cardNo, $definition->power ?? 0, $card->sideways);
        }
        return array_sum(PowerMod::apply($inputs, $this->globals->roundNo()));
    }

    /**
     * お守りの探査力（所持者だけに加算される）。
     */
    public function charmPower(int $playerId): int
    {
        $charm = $this->cards->list(Zone::Charm)[0] ?? null;
        if ($charm === null || $charm->locationArg !== $playerId) {
            return 0;
        }
        $definition = CardDefinitions::get(sprintf('%03d', $charm->cardNo));
        if ($definition === null) {
            return 0;
        }
        $zeroOnRound = null;
        foreach ($definition->effects as $effect) {
            if ($effect->kind === EffectKind::CharmPower && isset($effect->params['zero_on_round'])) {
                $zeroOnRound = (int) $effect->params['zero_on_round'];
            }
        }
        return ExploreOutput::charmPower($definition->power, $zeroOnRound, $this->globals->roundNo());
    }

    /**
     * 準備中の現在の探査出力（お守りを含み、半減は含めない。009 §8.3）。
     */
    public function outputNow(int $playerId): int
    {
        return ExploreOutput::now($this->total($playerId), $this->charmPower($playerId));
    }

    /**
     * 探査フェイズで確定する探査出力（半減あり。準備フェイズ③-3 の 1 人だけのドロー者は半分にする）。
     */
    public function decided(int $playerId, bool $halved): int
    {
        return ExploreOutput::decided($this->total($playerId), $this->charmPower($playerId), $halved);
    }
}
