<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * カード効果の記述子（008 §1.2）。1 つの効果タイミングラベルが 1 つの記述子に対応する。
 */
final readonly class EffectDefinition
{
    /**
     * @param array<string, mixed> $params kind ごとのパラメーター（008 §1.6）
     * @param array{state: int, action: string}|null $choice 選択が要る効果の状態 ID とアクション名（006）
     */
    public function __construct(
        public EffectTiming $timing,
        public EffectKind $kind,
        public array $params = [],
        public bool $optional = false,
        public ?DrawLevel $drawLevel = null,
        public ?PowerModStage $powerModStage = null,
        public ?PowerModTarget $powerModTarget = null,
        public bool $fameModExempt = false,
        public ?string $absorbedBy = null,
        public bool $ignoresBlock = false,
        public bool $immuneToSuppression = false,
        public ?string $suppressRule = null,
        public ?array $choice = null,
        public bool $readsDeck = false,
    ) {
    }
}
