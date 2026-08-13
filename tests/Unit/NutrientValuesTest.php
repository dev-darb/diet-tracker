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
}
