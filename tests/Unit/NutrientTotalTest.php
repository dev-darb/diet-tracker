<?php

namespace Tests\Unit;

use App\ValueObjects\NutrientTotal;
use App\ValueObjects\NutrientValues;
use PHPUnit\Framework\TestCase;

/**
 * A day total that keeps its gaps visible instead of collapsing into one
 * (founder decision, Aug 2026).
 */
class NutrientTotalTest extends TestCase
{
    public function test_a_known_figure_survives_an_unknown_alongside_it(): void
    {
        // The behaviour this object exists for: one figureless coffee used to
        // black out an otherwise fully recorded day.
        $total = NutrientTotal::of([
            NutrientValues::fromStated(['calories' => 800, 'fibre' => 6]),
            NutrientValues::fromStated(['calories' => 1050, 'fibre' => 4]),
            NutrientValues::fromStated([]), // the coffee nobody has figures for
        ]);

        $this->assertSame(1850.0, $total->known()->get('calories'));
        $this->assertSame(10.0, $total->known()->get('fibre'));

        $this->assertSame(2, $total->knownCount('calories'));
        $this->assertSame(1, $total->missing('calories'));
        $this->assertSame(3, $total->contributors('calories'));
        $this->assertFalse($total->isComplete('calories'));
    }

    /**
     * The strict reading is preserved, not replaced. Confidence and averages read
     * it, so a partial day scores — it just does not score as a certain day.
     */
    public function test_the_strict_reading_still_admits_the_gap(): void
    {
        $total = NutrientTotal::of([
            NutrientValues::fromStated(['calories' => 800]),
            NutrientValues::fromStated([]),
        ]);

        $this->assertSame(800.0, $total->known()->get('calories'));
        $this->assertNull($total->strict()->get('calories'));
    }

    /**
     * The trap this object could easily have fallen into: starting the partial
     * sum at zero would turn a day where NOTHING is known into a confident
     * "0 kcal" — the exact fabricated zero the data model exists to prevent.
     */
    public function test_a_day_with_nothing_stated_reads_unknown_not_zero(): void
    {
        $total = NutrientTotal::of([
            NutrientValues::fromStated([]),
            NutrientValues::fromStated([]),
        ]);

        $this->assertNull($total->known()->get('calories'));
        $this->assertNull($total->strict()->get('calories'));
        $this->assertSame(0, $total->knownCount('calories'));
        $this->assertSame(0.0, $total->coverage('calories'));
    }

    public function test_a_genuine_zero_is_still_a_known_figure(): void
    {
        $total = NutrientTotal::of([
            NutrientValues::fromStated(['calories' => 0.0]),
        ]);

        $this->assertSame(0.0, $total->known()->get('calories'));
        $this->assertTrue($total->isComplete('calories'));
        $this->assertSame(1.0, $total->coverage('calories'));
    }

    public function test_an_empty_total_claims_nothing(): void
    {
        $total = NutrientTotal::empty();

        $this->assertNull($total->known()->get('calories'));
        $this->assertSame(0, $total->contributors('calories'));
        $this->assertSame(0.0, $total->coverage('calories'));
    }

    public function test_coverage_is_reported_per_nutrient(): void
    {
        // Everything states calories; only one of three states iron.
        $total = NutrientTotal::of([
            NutrientValues::fromStated(['calories' => 100, 'iron' => 2.0]),
            NutrientValues::fromStated(['calories' => 200]),
            NutrientValues::fromStated(['calories' => 300]),
        ]);

        $this->assertSame(1.0, $total->coverage('calories'));
        $this->assertSame(round(1 / 3, 4), $total->coverage('iron'));
    }
}
