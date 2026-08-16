<?php

namespace App\AI\Local;

use App\AI\Contracts\NutritionEstimator;
use App\Nutrition\Estimation\EstimationDraft;
use App\Nutrition\Estimation\EstimationRequest;

/**
 * KEY-ABSENT GRACE for estimation: with no AI gateway key configured, nothing is
 * estimated and every flow that would have used one carries on without it. An
 * estimate is an upgrade over an honest gap, never a requirement for logging.
 */
class UnavailableNutritionEstimator implements NutritionEstimator
{
    public function available(): bool
    {
        return false;
    }

    public function estimate(EstimationRequest $request): ?EstimationDraft
    {
        return null;
    }
}
