<?php

namespace Tests\Unit;

use App\Nutrition\MeasuredAmount;
use App\Nutrition\NutritionSanityCheck;
use App\ValueObjects\NutrientValues;
use PHPUnit\Framework\TestCase;

/**
 * The cross-checks catch figures that are each individually possible but cannot
 * all be true at once — the failure mode individual range checks cannot see.
 */
class NutritionSanityCheckTest extends TestCase
{
    public function test_a_coherent_product_produces_no_findings(): void
    {
        // Snickers, per 100 g: 497 kcal against macros implying ~502.
        $values = NutrientValues::fromStated([
            'calories' => 497, 'protein' => 9.4, 'carbs' => 57,
            'sugars' => 51, 'fat' => 24, 'saturated_fat' => 9,
        ]);

        $this->assertSame([], NutritionSanityCheck::inspect($values));
    }

    public function test_sugars_above_carbohydrate_is_reported(): void
    {
        $values = NutrientValues::fromStated(['carbs' => 12, 'sugars' => 40]);

        $findings = NutritionSanityCheck::inspect($values);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('exceeds carbs', $findings[0]);
    }

    public function test_saturates_above_total_fat_is_reported(): void
    {
        $values = NutrientValues::fromStated(['fat' => 3, 'saturated_fat' => 9]);

        $this->assertStringContainsString('exceeds fat', NutritionSanityCheck::inspect($values)[0]);
    }

    public function test_components_totalling_more_than_the_food_weighs_are_reported(): void
    {
        $values = NutrientValues::fromStated(['protein' => 40, 'carbs' => 50, 'fat' => 30]);

        $findings = NutritionSanityCheck::inspect($values);

        $this->assertNotEmpty(array_filter(
            $findings,
            static fn (string $f): bool => str_contains($f, 'more than the food weighs'),
        ));
    }

    /** A kilojoule figure filed as kcal is the classic version of this. */
    public function test_energy_that_does_not_follow_from_the_macros_is_reported(): void
    {
        $values = NutrientValues::fromStated([
            'calories' => 1462, 'protein' => 8.5, 'carbs' => 78, 'fat' => 1.2,
        ]);

        $findings = NutritionSanityCheck::inspect($values);

        $this->assertNotEmpty(array_filter(
            $findings,
            static fn (string $f): bool => str_contains($f, 'away from'),
        ));
    }

    public function test_energy_is_not_judged_without_all_three_load_bearing_macros(): void
    {
        // Protein unstated: the estimate would be meaningless, so it stays quiet
        // rather than crying wolf on an incomplete record.
        $values = NutrientValues::fromStated(['calories' => 500, 'carbs' => 10, 'fat' => 1]);

        $this->assertSame([], NutritionSanityCheck::inspect($values));
    }

    public function test_a_serving_larger_than_its_pack_is_reported(): void
    {
        $findings = NutritionSanityCheck::inspect(
            NutrientValues::fromStated([]),
            MeasuredAmount::grams(400),
            MeasuredAmount::grams(250),
        );

        $this->assertStringContainsString('larger than the whole pack', $findings[0]);
    }

    public function test_columns_report_coverage_and_keep_clean_findings_null(): void
    {
        $values = NutrientValues::fromStated([
            'calories' => 497, 'protein' => 9.4, 'carbs' => 57,
            'sugars' => 51, 'fat' => 24, 'saturated_fat' => 9,
        ]);

        $columns = NutritionSanityCheck::columns($values);

        // Null, not [] — "checked, nothing amiss", never "checked, no result".
        $this->assertNull($columns['sanity_findings']);
        $this->assertSame(round(6 / 23, 4), $columns['nutrient_coverage']);
    }

    public function test_findings_never_alter_the_figures_they_describe(): void
    {
        $values = NutrientValues::fromStated(['carbs' => 12, 'sugars' => 40]);

        NutritionSanityCheck::inspect($values);

        // A corrected figure would be a fabricated figure. The data is reported
        // on, never touched.
        $this->assertSame(40.0, $values->get('sugars'));
        $this->assertSame(12.0, $values->get('carbs'));
    }
}
