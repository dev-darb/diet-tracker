<?php

namespace App\AI\Local;

use App\AI\Contracts\EatingOutEstimator;
use App\AI\DataObjects\EatingOutEstimate;

/**
 * KEY-ABSENT GRACE for eating-out estimation: with no AI gateway key the
 * capture flow quietly falls back to manual figures (all optional) — a meal
 * can always be logged, estimation is an upgrade, never a requirement.
 */
class UnavailableEatingOutEstimator implements EatingOutEstimator
{
    public function available(): bool
    {
        return false;
    }

    public function estimate(string $dish, ?string $venue = null): ?EatingOutEstimate
    {
        return null;
    }
}
