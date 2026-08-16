<?php

namespace App\Nutrition\Estimation;

use App\Models\NutritionEstimate;
use App\Nutrition\NutrientOrigin;
use App\Nutrition\NutrientOrigins;

/**
 * What one estimation run produced: the ruling, the figures if any survived, and
 * the record that explains them.
 */
final class EstimationOutcome
{
    public function __construct(
        public readonly EstimateVerdict $verdict,
        public readonly NutritionEstimate $record,
    ) {}

    public function isAccepted(): bool
    {
        return $this->verdict->isAccepted;
    }

    /**
     * The accepted figures.
     *
     * @return array<string, float>
     */
    public function values(): array
    {
        return $this->verdict->values;
    }

    /**
     * The origin map for those figures: every one marked estimated, and carrying
     * the id of the record that holds its working, so the trail from a number on
     * a screen back to the reasoning behind it is one lookup.
     *
     * Exposed beside {@see values()} on purpose. A caller that took the numbers
     * without this would write a figure indistinguishable from a label reading,
     * which is the single outcome the whole design exists to prevent.
     */
    public function origins(?NutrientOrigins $onto = null): NutrientOrigins
    {
        return ($onto ?? NutrientOrigins::none())->mark(
            $this->verdict->keys(),
            NutrientOrigin::Estimated,
            (string) $this->record->id,
        );
    }
}
