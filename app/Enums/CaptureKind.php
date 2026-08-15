<?php

namespace App\Enums;

/**
 * What the scanner decided it is looking at (unified capture, Aug 2026).
 * Classification is SEPARATE from user intent: a packaged product or a loose
 * ingredient may be eaten now or stocked; a prepared meal proceeds into meal
 * analysis/logging. `Unknown` means the triage was genuinely uncertain — the
 * one case where asking the user beats guessing.
 */
enum CaptureKind: string
{
    /** A branded, packaged grocery product (label, barcode, wrapper). */
    case PackagedProduct = 'packaged_product';

    /** A loose food or ingredient: a banana, raw chicken breast, bakery roll. */
    case IngredientOrFood = 'ingredient_or_food';

    /** A prepared plate/dish — routed into meal analysis, not the pantry gate. */
    case PreparedMeal = 'prepared_meal';

    /** Triage couldn't tell — the card asks instead of guessing. */
    case Unknown = 'unknown';

    public static function fromModel(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }

    /** Kinds that proceed down the product identification → provenance gate. */
    public function identifiesAsProduct(): bool
    {
        return $this === self::PackagedProduct || $this === self::IngredientOrFood;
    }

    /** Natural-language phrasing for the identifier's user-asserted kind hint. */
    public function hintLabel(): string
    {
        return match ($this) {
            self::PackagedProduct => 'packaged grocery product',
            self::IngredientOrFood => 'loose ingredient or unprepared food',
            self::PreparedMeal => 'prepared meal',
            self::Unknown => 'food item',
        };
    }
}
