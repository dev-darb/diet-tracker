<?php

namespace App\Nutrition;

use App\Models\NutritionEstimate;

/**
 * Where one nutrient figure actually came from (founder decision, Aug 2026).
 *
 * The app's original rule was that a model must never produce a nutrient value.
 * That rule was too blunt: it left a coffee unloggable, and it treated every
 * absent figure as equally unknowable. The rule that replaced it is narrower and
 * harder — a model MAY produce a figure, within guardrails, but the figure must
 * never be mistakable for one a source stated.
 *
 * That only works if provenance is per NUTRIENT, not per record. A product
 * version carrying six stated macros and two estimated ones is not "a source" or
 * "an estimate"; it is both, field by field, and the app has to be able to say
 * which is which — on the screen, in six months, without guessing.
 *
 * Stored compactly as `<origin>` or `<origin>:<detail>` in a `nutrient_origins`
 * map, keyed by nutrient. An ABSENT key means {@see Stated}, so the common case
 * — a product read straight from a label — costs nothing to record.
 */
enum NutrientOrigin: string
{
    /**
     * A source stated this figure outright. The default, and the only origin
     * that needs no marker anywhere in the interface.
     */
    case Stated = 'stated';

    /**
     * Deterministic arithmetic on a figure a source did state: energy converted
     * from kilojoules, salt from sodium, a per-serving figure renormalised to
     * per-100. The detail carries which source field it came from.
     *
     * This is real data in a different unit — not an estimate, and not something
     * to apologise for. It is recorded so the conversion can be audited, not so
     * it can be doubted.
     */
    case Derived = 'derived';

    /**
     * A model produced this figure by reasoning, because no source had it.
     * Always accompanied by a {@see NutritionEstimate} carrying the
     * working, and always marked wherever the figure is shown.
     */
    case Estimated = 'estimated';

    /** The user typed it in themselves. Their kitchen, their number. */
    case UserStated = 'user';

    /** Whether a figure of this origin should carry a visible marker. */
    public function needsMarker(): bool
    {
        return $this === self::Estimated;
    }

    /** Short words for the detail surface. */
    public function label(): string
    {
        return match ($this) {
            self::Stated => 'From the label',
            self::Derived => 'Converted from the label',
            self::Estimated => 'Estimated',
            self::UserStated => 'You entered this',
        };
    }
}
