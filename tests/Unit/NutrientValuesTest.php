<?php

namespace Tests\Unit;

use App\ValueObjects\NutrientValues;
use PHPUnit\Framework\TestCase;

class NutrientValuesTest extends TestCase
{
    public function test_zero_is_all_zero(): void
    {
        $v = NutrientValues::zero();

        $this->assertSame(0.0, $v->calories);
        $this->assertSame(0.0, $v->protein);
        $this->assertSame(0.0, $v->salt);
    }

    public function test_from_array_handles_numeric_strings_and_missing_keys(): void
    {
        $v = NutrientValues::fromArray([
            'calories' => '250.5',
            'protein' => 10,
            'saturated_fat' => '2.25',
        ]);

        $this->assertSame(250.5, $v->calories);
        $this->assertSame(10.0, $v->protein);
        $this->assertSame(2.25, $v->saturatedFat);
        $this->assertSame(0.0, $v->carbs);
        $this->assertSame(0.0, $v->salt);
    }

    public function test_scale_multiplies_every_nutrient(): void
    {
        $scaled = (new NutrientValues(calories: 100, protein: 5, salt: 1))->scale(1.5);

        $this->assertSame(150.0, $scaled->calories);
        $this->assertSame(7.5, $scaled->protein);
        $this->assertSame(1.5, $scaled->salt);
    }

    public function test_add_is_component_wise(): void
    {
        $a = new NutrientValues(calories: 100, protein: 5);
        $b = new NutrientValues(calories: 50, protein: 2.5, fat: 3);

        $sum = $a->add($b);

        $this->assertSame(150.0, $sum->calories);
        $this->assertSame(7.5, $sum->protein);
        $this->assertSame(3.0, $sum->fat);
    }

    public function test_rounding_happens_only_at_the_edge(): void
    {
        $v = new NutrientValues(calories: 33.333333, protein: 1.005, salt: 0.126);

        $rounded = $v->rounded(2);

        $this->assertSame(33.33, $rounded->calories);
        $this->assertSame(0.13, $rounded->salt);
        // Original untouched (immutable).
        $this->assertSame(33.333333, $v->calories);
    }

    public function test_to_array_uses_db_column_keys(): void
    {
        $v = new NutrientValues(
            calories: 200, protein: 10, carbs: 20, sugars: 5,
            fat: 8, saturatedFat: 3, fibre: 4, salt: 1.2,
        );

        $this->assertSame([
            'calories' => 200.0,
            'protein' => 10.0,
            'carbs' => 20.0,
            'sugars' => 5.0,
            'fat' => 8.0,
            'saturated_fat' => 3.0,
            'fibre' => 4.0,
            'salt' => 1.2,
        ], $v->toArray());
    }

    public function test_scale_and_add_return_new_instances(): void
    {
        $v = new NutrientValues(calories: 100);

        $this->assertNotSame($v, $v->scale(2));
        $this->assertNotSame($v, $v->add(NutrientValues::zero()));
        $this->assertSame(100.0, $v->calories);
    }

    // --- Unknown vs zero (Milestone 2, brief §2.1) --------------------------

    public function test_from_array_present_null_is_unknown_absent_is_zero(): void
    {
        $v = NutrientValues::fromArray([
            'calories' => 250,
            'fibre' => null,   // present but not stated → unknown
            // 'salt' absent → known zero (backward compatible)
        ]);

        $this->assertSame(250.0, $v->calories);
        $this->assertNull($v->fibre);
        $this->assertSame(0.0, $v->salt);
    }

    public function test_explicit_null_nutrient_is_unknown(): void
    {
        $v = new NutrientValues(calories: 100, fibre: null, salt: null);

        $this->assertFalse($v->isKnown('fibre'));
        $this->assertTrue($v->isKnown('calories'));
        $this->assertFalse($v->isComplete());
        $this->assertSame(['fibre', 'salt'], $v->unknownKeys());
    }

    public function test_scale_preserves_unknowns_and_never_fabricates_zero(): void
    {
        $v = new NutrientValues(calories: 400, fibre: null);

        $scaled = $v->scale(0.5);

        $this->assertSame(200.0, $scaled->calories);
        $this->assertNull($scaled->fibre);
    }

    public function test_rounding_preserves_unknowns(): void
    {
        $v = new NutrientValues(calories: 33.333, salt: null);

        $rounded = $v->rounded(2);

        $this->assertSame(33.33, $rounded->calories);
        $this->assertNull($rounded->salt);
    }

    public function test_add_propagates_unknowns(): void
    {
        $known = new NutrientValues(calories: 100, fibre: 5, salt: 1);
        $partial = new NutrientValues(calories: 50, fibre: null, salt: 0.5);

        $sum = $known->add($partial);

        $this->assertSame(150.0, $sum->calories); // both known → summed
        $this->assertNull($sum->fibre);           // one unknown → total unknown
        $this->assertSame(1.5, $sum->salt);
    }

    public function test_known_zero_is_distinct_from_unknown(): void
    {
        $knownZero = new NutrientValues(fibre: 0.0);
        $unknown = new NutrientValues(fibre: null);

        $this->assertSame(0.0, $knownZero->fibre);
        $this->assertTrue($knownZero->isKnown('fibre'));
        $this->assertNull($unknown->fibre);
        $this->assertFalse($unknown->isKnown('fibre'));
    }

    public function test_to_array_exports_unknowns_as_null(): void
    {
        $v = new NutrientValues(calories: 200, fibre: null);

        $array = $v->toArray();

        $this->assertSame(200.0, $array['calories']);
        $this->assertNull($array['fibre']);
    }
}
