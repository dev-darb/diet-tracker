<?php

namespace Tests\Unit;

use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Nutrition\MeasuredAmount;
use App\Services\NutritionCalculator;
use App\ValueObjects\NutrientValues;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NutritionCalculatorTest extends TestCase
{
    private NutritionCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new NutritionCalculator;
    }

    /** Shorthand: a serving/pack size as the real mass the calculator now demands. */
    private static function g(float $grams): ?MeasuredAmount
    {
        return MeasuredAmount::grams($grams);
    }

    // --- Basis conversion (brief §7.12) -------------------------------------

    public function test_same_basis_returns_unchanged(): void
    {
        $v = new NutrientValues(calories: 100, protein: 10);
        $out = $this->calc->convertBasis($v, ServingBasis::Per100g, ServingBasis::Per100g, null);

        $this->assertSame(100.0, $out->calories);
        $this->assertSame(10.0, $out->protein);
    }

    public function test_per_100g_to_per_serving(): void
    {
        $per100 = new NutrientValues(calories: 400, protein: 8, salt: 1);
        $perServing = $this->calc->convertBasis($per100, ServingBasis::Per100g, ServingBasis::PerServing, self::g(30));

        $this->assertSame(120.0, $perServing->calories);
        $this->assertSame(2.4, $perServing->protein);
        $this->assertSame(0.3, $perServing->salt);
    }

    public function test_per_serving_to_per_100g(): void
    {
        $perServing = new NutrientValues(calories: 120, protein: 2.4);
        $per100 = $this->calc->convertBasis($perServing, ServingBasis::PerServing, ServingBasis::Per100g, self::g(30));

        $this->assertSame(400.0, $per100->calories);
        $this->assertSame(8.0, $per100->protein);
    }

    public function test_basis_conversion_round_trip_is_lossless(): void
    {
        $per100 = new NutrientValues(calories: 537, protein: 6.3, carbs: 57.5, salt: 1.2);

        $there = $this->calc->convertBasis($per100, ServingBasis::Per100g, ServingBasis::PerServing, self::g(25));
        $back = $this->calc->convertBasis($there, ServingBasis::PerServing, ServingBasis::Per100g, self::g(25));

        $this->assertSame($per100->rounded(6)->toArray(), $back->rounded(6)->toArray());
    }

    public function test_convert_without_serving_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->convertBasis(new NutrientValues(calories: 100), ServingBasis::Per100g, ServingBasis::PerServing, null);
    }

    public function test_convert_with_zero_serving_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->convertBasis(new NutrientValues(calories: 100), ServingBasis::Per100g, ServingBasis::PerServing, self::g(0));
    }

    // --- Consumption maths: grams / ml (brief §8.2, §8.9) -------------------

    public function test_grams_of_a_per_100g_product(): void
    {
        $per100 = new NutrientValues(calories: 400, protein: 8, carbs: 50);
        $c = $this->calc->contribution($per100, ServingBasis::Per100g, self::g(30), 150, QuantityUnit::Gram);

        $this->assertSame(600.0, $c->calories);
        $this->assertSame(12.0, $c->protein);
        $this->assertSame(75.0, $c->carbs);
    }

    public function test_ml_of_a_per_100ml_product(): void
    {
        $per100 = new NutrientValues(calories: 42, sugars: 10.6);
        $c = $this->calc->contribution($per100, ServingBasis::Per100g, null, 330, QuantityUnit::Millilitre);

        $this->assertSame(138.6, round($c->calories, 2));
        $this->assertSame(34.98, round($c->sugars, 2));
    }

    public function test_grams_of_a_per_serving_product_uses_serving_size(): void
    {
        $perServing = new NutrientValues(calories: 120, protein: 2.4);
        $c = $this->calc->contribution($perServing, ServingBasis::PerServing, self::g(30), 150, QuantityUnit::Gram);

        $this->assertSame(600.0, $c->calories);
        $this->assertSame(12.0, round($c->protein, 2));
    }

    // --- Consumption maths: units / portions -------------------------------

    public function test_n_units_of_a_per_serving_product(): void
    {
        $perServing = new NutrientValues(calories: 120, protein: 2.4);
        $c = $this->calc->contribution($perServing, ServingBasis::PerServing, self::g(30), 2, QuantityUnit::Unit);

        $this->assertSame(240.0, $c->calories);
        $this->assertSame(4.8, $c->protein);
    }

    public function test_portions_of_a_per_100g_product(): void
    {
        $per100 = new NutrientValues(calories: 400);
        $c = $this->calc->contribution($per100, ServingBasis::Per100g, self::g(30), 2, QuantityUnit::Portion);

        $this->assertSame(240.0, $c->calories);
    }

    public function test_unit_and_portion_are_equivalent(): void
    {
        $per100 = new NutrientValues(calories: 400, protein: 8);
        $asUnit = $this->calc->contribution($per100, ServingBasis::Per100g, self::g(30), 3, QuantityUnit::Unit);
        $asPortion = $this->calc->contribution($per100, ServingBasis::Per100g, self::g(30), 3, QuantityUnit::Portion);

        $this->assertSame($asUnit->toArray(), $asPortion->toArray());
    }

    // --- Consumption maths: fractions & packs ------------------------------

    public function test_fractional_serving(): void
    {
        $perServing = new NutrientValues(calories: 120, protein: 2.4);
        $c = $this->calc->contribution($perServing, ServingBasis::PerServing, self::g(30), 0.5, QuantityUnit::Unit);

        $this->assertSame(60.0, $c->calories);
        $this->assertSame(1.2, $c->protein);
    }

    public function test_fraction_of_a_pack_uses_pack_size(): void
    {
        $per100 = new NutrientValues(calories: 400, protein: 8);
        $c = $this->calc->contribution($per100, ServingBasis::Per100g, self::g(30), 0.5, QuantityUnit::Pack, packSize: self::g(250));

        $this->assertSame(500.0, $c->calories);
        $this->assertSame(10.0, $c->protein);
    }

    public function test_pack_without_pack_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->contribution(new NutrientValues(calories: 400), ServingBasis::Per100g, self::g(30), 1, QuantityUnit::Pack);
    }

    // --- Edge cases --------------------------------------------------------

    public function test_zero_quantity_returns_zero(): void
    {
        $per100 = new NutrientValues(calories: 400, protein: 8);
        $c = $this->calc->contribution($per100, ServingBasis::Per100g, self::g(30), 0, QuantityUnit::Gram);

        $this->assertSame(NutrientValues::zero()->toArray(), $c->toArray());
    }

    public function test_grams_of_per_100g_needs_no_serving_size(): void
    {
        $per100 = new NutrientValues(calories: 400);
        $c = $this->calc->contribution($per100, ServingBasis::Per100g, null, 50, QuantityUnit::Gram);

        $this->assertSame(200.0, $c->calories);
    }

    public function test_units_of_per_100g_without_serving_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->contribution(new NutrientValues(calories: 400), ServingBasis::Per100g, null, 1, QuantityUnit::Unit);
    }

    public function test_grams_of_per_serving_without_serving_size_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->contribution(new NutrientValues(calories: 120), ServingBasis::PerServing, null, 50, QuantityUnit::Gram);
    }

    public function test_negative_quantity_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->contribution(new NutrientValues(calories: 400), ServingBasis::Per100g, self::g(30), -1, QuantityUnit::Gram);
    }

    // --- Meal / day summation (brief §8.4) ---------------------------------

    public function test_sum_of_empty_list_is_zero(): void
    {
        $this->assertSame(NutrientValues::zero()->toArray(), $this->calc->sum([])->toArray());
    }

    public function test_sum_of_a_meal(): void
    {
        $bread = $this->calc->contribution(
            new NutrientValues(calories: 265, protein: 9, carbs: 49),
            ServingBasis::Per100g, null, 60, QuantityUnit::Gram,
        );
        $butter = $this->calc->contribution(
            new NutrientValues(calories: 717, fat: 81, saturatedFat: 51),
            ServingBasis::Per100g, null, 10, QuantityUnit::Gram,
        );
        $jam = $this->calc->contribution(
            new NutrientValues(calories: 250, sugars: 60, carbs: 62),
            ServingBasis::Per100g, null, 20, QuantityUnit::Gram,
        );

        $total = $this->calc->sum([$bread, $butter, $jam])->rounded(2);

        $this->assertSame(280.7, $total->calories); // 159 + 71.7 + 50
        $this->assertSame(41.8, $total->carbs);      // 29.4 + 12.4
        $this->assertSame(8.1, $total->fat);
        $this->assertSame(12.0, $total->sugars);
    }

    public function test_full_precision_kept_through_summation(): void
    {
        $piece = $this->calc->contribution(
            new NutrientValues(calories: 100),
            ServingBasis::Per100g, null, 10 / 3, QuantityUnit::Gram,
        );

        $total = $this->calc->sum([$piece, $piece, $piece])->rounded(2);

        $this->assertSame(10.0, $total->calories); // 3 × (10/3)g × 1 kcal/g
    }

    // --- Unknown nutrients (brief §2.1) ------------------------------------

    public function test_contribution_keeps_unknown_nutrients_unknown(): void
    {
        // A product whose fibre/salt OFF never stated.
        $per100 = new NutrientValues(calories: 400, protein: 8, fibre: null, salt: null);

        $c = $this->calc->contribution($per100, ServingBasis::Per100g, null, 150, QuantityUnit::Gram);

        $this->assertSame(600.0, $c->calories);
        $this->assertSame(12.0, $c->protein);
        $this->assertNull($c->fibre); // never fabricated to 0
        $this->assertNull($c->salt);
    }

    public function test_meal_total_is_unknown_for_a_nutrient_any_component_lacks(): void
    {
        $known = $this->calc->contribution(
            new NutrientValues(calories: 200, fibre: 3),
            ServingBasis::Per100g, null, 100, QuantityUnit::Gram,
        );
        $partial = $this->calc->contribution(
            new NutrientValues(calories: 150, fibre: null),
            ServingBasis::Per100g, null, 100, QuantityUnit::Gram,
        );

        $total = $this->calc->sum([$known, $partial])->rounded(2);

        $this->assertSame(350.0, $total->calories); // both known → summed honestly
        $this->assertNull($total->fibre);           // one unknown → total unknown, not understated
    }
}
