<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Material;

/**
 * 効果の種類（008 §1.6）。先頭 16 個が共通ハンドラ、残り 9 個が固有ハンドラ。
 */
enum EffectKind: string
{
    case DeckTopPeek = 'DECK_TOP_PEEK';
    case DeckSearchToTop = 'DECK_SEARCH_TO_TOP';
    case ReturnAndRedraw = 'RETURN_AND_REDRAW';
    case ExtraPrepareFinished = 'EXTRA_PREPARE_FINISHED';
    case NextChoiceRestriction = 'NEXT_CHOICE_RESTRICTION';
    case DrawSuppression = 'DRAW_SUPPRESSION';
    case CostModifier = 'COST_MODIFIER';
    case ExploreBan = 'EXPLORE_BAN';
    case PowerSelfBonus = 'POWER_SELF_BONUS';
    case PowerRewrite = 'POWER_REWRITE';
    case PowerNegate = 'POWER_NEGATE';
    case PowerReference = 'POWER_REFERENCE';
    case CharmPower = 'CHARM_POWER';
    case FameBonus = 'FAME_BONUS';
    case AcquireExcludePrep = 'ACQUIRE_EXCLUDE_PREP';
    case MarkTopAfterShuffle = 'MARK_TOP_AFTER_SHUFFLE';
    case LockRowCard = 'LOCK_ROW_CARD';
    case CopyDrawEffect = 'COPY_DRAW_EFFECT';
    case AddPileTopToRow = 'ADD_PILE_TOP_TO_ROW';
    case AdditionalExplore = 'ADDITIONAL_EXPLORE';
    case FreeAcquire = 'FREE_ACQUIRE';
    case SwapRowWithDeckTop = 'SWAP_ROW_WITH_DECK_TOP';
    case ReturnDrawnCard = 'RETURN_DRAWN_CARD';
    case AttachCard = 'ATTACH_CARD';
    case ExcludeFromDeck = 'EXCLUDE_FROM_DECK';
}
