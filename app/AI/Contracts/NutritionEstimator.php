<?php

namespace App\AI\Contracts;

use App\Nutrition\Estimation\EstimateGuard;
use App\Nutrition\Estimation\EstimationDraft;
use App\Nutrition\Estimation\EstimationRequest;

/**
 * Produces a nutrition PROPOSAL for a food no source has figures for.
 *
 * Note the return type: a {@see EstimationDraft}, not a set of figures. An
 * implementation cannot hand back numbers that are ready to use, because nothing
 * here decides whether its own answer is good enough — that is
 * {@see EstimateGuard}'s job, and it is deterministic.
 *
 * Reliability contract, as with every AI capability in the app: estimate() never
 * throws into the request path. A provider failure returns null and the flow
 * carries on without an estimate.
 */
interface NutritionEstimator
{
    public function available(): bool;

    public function estimate(EstimationRequest $request): ?EstimationDraft;
}
