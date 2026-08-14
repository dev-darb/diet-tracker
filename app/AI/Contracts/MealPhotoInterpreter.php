<?php

namespace App\AI\Contracts;

use App\AI\DataObjects\MealPhotoReading;
use App\AI\DataObjects\ProductImage;

/**
 * Reads a photo of a meal and proposes what's on the plate (capture-flow
 * Phase B; BUILD_PLAN §1b). Two uses, one capability:
 *
 *  - Home-cooked: match visible components against the user's PANTRY CANDIDATES
 *    (recognise-and-select — our structural advantage over calorie-camera apps).
 *  - Eating out: name the dish so the EatingOutEstimator can price it.
 *
 * Contract rules: the reading is a proposal the user confirms — nothing is
 * auto-logged; matched ids come strictly from the supplied candidates; visible
 * non-pantry foods are reported, never silently dropped; `interpret()` returns
 * null on failure/unavailability so callers degrade to manual entry.
 */
interface MealPhotoInterpreter
{
    /** Whether live interpretation is configured (an AI gateway key is present). */
    public function available(): bool;

    /**
     * @param  list<array{id: int, label: string}>  $pantryCandidates  the user's
     *                                                                 in-stock items; empty for eating-out (no grounding wanted).
     */
    public function interpret(ProductImage $photo, array $pantryCandidates = []): ?MealPhotoReading;
}
