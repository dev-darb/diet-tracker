<?php

namespace App\AI\Contracts;

use App\AI\DataObjects\EatingOutEstimate;

/**
 * Estimates the nutritional picture of an eating-out meal from its name and
 * (optionally) the venue (capture-flow Phase B; BUILD_PLAN §1b tier 3). The
 * user should never be expected to know calorie or macro figures at a
 * restaurant table — estimation is the feature, manual entry is the correction.
 *
 * Contract rules (brief §2.1 adapted for tier-3 data):
 *  - Figures are typical-composition / published-menu ESTIMATES with an honest
 *    confidence and a stated basis; anything unguessable stays null.
 *  - The estimate is a PROPOSAL: the caller shows it for confirmation/edit and
 *    records the result marked `estimated`. Nothing is auto-committed.
 *  - `estimate()` returns null when no estimate could be produced (unavailable,
 *    provider failure) — callers degrade to manual entry, never an error.
 */
interface EatingOutEstimator
{
    /** Whether live estimation is configured (an AI gateway key is present). */
    public function available(): bool;

    public function estimate(string $dish, ?string $venue = null): ?EatingOutEstimate;
}
