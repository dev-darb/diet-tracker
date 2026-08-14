<?php

namespace Tests\Feature;

use App\Enums\ConsumptionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\User;
use App\Services\ConsumptionService;
use App\Services\NutritionAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NutritionAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private NutritionAnalyticsService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NutritionAnalyticsService::class);
        $this->user = User::factory()->create();
    }

    /**
     * Log an event on $date with one or more snapshotted items. Each item is
     * `['nutrients' => [...], 'product' => ?CanonicalProduct]`.
     *
     * @param  array<int, array{nutrients: array<string, float|null>, product?: ?CanonicalProduct}>  $items
     */
    private function logDay(string $date, array $items): ConsumptionEvent
    {
        $keys = ['calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt'];

        // Mirror the real services: event columns carry the summed snapshot
        // (nulls propagate) — day totals read the EVENT, so eating-out entries
        // (which have no item rows) count too.
        $totals = [];
        foreach ($keys as $key) {
            $values = array_map(
                fn (array $item) => array_merge(array_fill_keys($keys, 0.0), $item['nutrients'])[$key],
                $items,
            );
            $totals[$key] = in_array(null, $values, true) ? null : array_sum($values);
        }

        $event = ConsumptionEvent::factory()->for($this->user)->create([
            'type' => ConsumptionType::Single,
            'consumed_at' => Carbon::parse($date.' 12:00:00'),
            ...$totals,
        ]);

        foreach ($items as $item) {
            $event->items()->create([
                'canonical_product_id' => ($item['product'] ?? null)?->id,
                'product_version_id' => null,
                'quantity' => 1,
                'unit' => QuantityUnit::Unit,
                ...array_merge(array_fill_keys($keys, 0.0), $item['nutrients']),
            ]);
        }

        return $event;
    }

    // --- the reframe (§1b): every context reaches the dashboard -------------

    public function test_eating_out_estimates_flow_into_daily_and_weekly_totals(): void
    {
        $this->logDay('2026-08-13', [['nutrients' => ['calories' => 400, 'protein' => 20]]]);

        app(ConsumptionService::class)->logEatingOut(
            $this->user,
            'Katsu curry at Wagamama',
            ['calories' => 1180, 'protein' => 45],
            Carbon::parse('2026-08-13 19:00:00'),
        );

        $daily = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));
        $this->assertSame(1580.0, $daily['totals']['calories']);
        $this->assertSame(65.0, $daily['totals']['protein']);

        $weekly = $this->service->weeklySummary($this->user, Carbon::parse('2026-08-13'));
        $this->assertSame(1580.0, $weekly['averages']['calories']['value']); // one logged day
    }

    public function test_figureless_eating_out_day_is_logged_but_unknown_never_zero(): void
    {
        app(ConsumptionService::class)->logEatingOut(
            $this->user,
            'Dinner with friends',
            [],
            Carbon::parse('2026-08-13 20:00:00'),
        );

        $daily = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));

        // The meal is ON the record (meal regularity counts it)…
        $this->assertTrue($daily['has_data']);
        // …but its figures are honestly unknown, never a fabricated 0 kcal.
        $this->assertNull($daily['totals']['calories']);
        $this->assertContains('calories', $daily['unknown_nutrients']);
    }

    public function test_daily_aggregate_sums_snapshots_across_items(): void
    {
        $this->logDay('2026-08-13', [
            ['nutrients' => ['calories' => 300, 'protein' => 20, 'fibre' => 5, 'salt' => 1]],
            ['nutrients' => ['calories' => 250, 'protein' => 15, 'fibre' => 4, 'salt' => 0.5]],
        ]);

        $summary = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));

        $this->assertTrue($summary['has_data']);
        $this->assertSame(550.0, $summary['totals']['calories']);
        $this->assertSame(35.0, $summary['totals']['protein']);
        $this->assertSame(9.0, $summary['totals']['fibre']);
        $this->assertSame(1.5, $summary['totals']['salt']);
        $this->assertSame([], $summary['unknown_nutrients']);
    }

    public function test_unknown_nutrient_day_reports_partial_not_zero(): void
    {
        // One item has an unknown (null) fibre — the day's fibre must be UNKNOWN,
        // never an understated 0 (brief §2.1).
        $this->logDay('2026-08-13', [
            ['nutrients' => ['calories' => 300, 'fibre' => 5]],
            ['nutrients' => ['calories' => 250, 'fibre' => null]],
        ]);

        $summary = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));

        $this->assertSame(550.0, $summary['totals']['calories']);
        $this->assertNull($summary['totals']['fibre']);
        $this->assertContains('fibre', $summary['unknown_nutrients']);

        // The fibre indicator is Unknown, not banded against 0.
        $fibre = collect($summary['indicators'])->firstWhere('key', 'fibre');
        $this->assertSame(NutritionAnalyticsService::BAND_UNKNOWN, $fibre['band']);
        $this->assertFalse($fibre['known']);
    }

    public function test_empty_day_has_no_data(): void
    {
        $summary = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));

        $this->assertFalse($summary['has_data']);
        $this->assertSame(0, $summary['food_variety']);
    }

    public function test_seven_day_average_and_previous_week_trend_delta(): void
    {
        $end = '2026-08-13';

        // Previous week (07-31..08-06): 100g protein each day → avg 100.
        foreach (['2026-07-31', '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'] as $d) {
            $this->logDay($d, [['nutrients' => ['protein' => 100, 'calories' => 2000]]]);
        }

        // Current week (08-07..08-13): 110g protein each day → avg 110.
        foreach (['2026-08-07', '2026-08-08', '2026-08-09', '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13'] as $d) {
            $this->logDay($d, [['nutrients' => ['protein' => 110, 'calories' => 2100]]]);
        }

        $summary = $this->service->weeklySummary($this->user, Carbon::parse($end));

        $this->assertSame(110.0, $summary['averages']['protein']['value']);
        $this->assertSame(7, $summary['averages']['protein']['known_days']);
        $this->assertFalse($summary['averages']['protein']['partial']);

        // Trend: (110 - 100) / 100 = +0.10.
        $this->assertSame(0.1, $summary['trends']['protein']['delta']);
        $this->assertSame('up', $summary['trends']['protein']['direction']);
        $this->assertTrue($summary['trends']['protein']['comparable']);

        $this->assertSame(2100.0, $summary['averages']['calories']['value']);
    }

    public function test_trend_not_comparable_without_previous_data(): void
    {
        $this->logDay('2026-08-13', [['nutrients' => ['protein' => 90]]]);

        $summary = $this->service->weeklySummary($this->user, Carbon::parse('2026-08-13'));

        $this->assertNull($summary['trends']['protein']['delta']);
        $this->assertFalse($summary['trends']['protein']['comparable']);
    }

    public function test_average_skips_unknown_days_and_flags_partial(): void
    {
        // Two logged days; fibre known on one, unknown on the other.
        $this->logDay('2026-08-12', [['nutrients' => ['fibre' => 20]]]);
        $this->logDay('2026-08-13', [['nutrients' => ['fibre' => null]]]);

        $summary = $this->service->weeklySummary($this->user, Carbon::parse('2026-08-13'));

        // Average over the single KNOWN day, not diluted by the unknown one.
        $this->assertSame(20.0, $summary['averages']['fibre']['value']);
        $this->assertSame(1, $summary['averages']['fibre']['known_days']);
        $this->assertTrue($summary['averages']['fibre']['partial']);
    }

    public function test_food_variety_counts_distinct_products(): void
    {
        $a = CanonicalProduct::factory()->create();
        $b = CanonicalProduct::factory()->create();

        // Same product twice + a second product in one day → 2 distinct.
        $this->logDay('2026-08-13', [
            ['nutrients' => ['calories' => 100], 'product' => $a],
            ['nutrients' => ['calories' => 100], 'product' => $a],
            ['nutrients' => ['calories' => 100], 'product' => $b],
        ]);

        $daily = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));
        $this->assertSame(2, $daily['food_variety']);

        // Across the week: a third product on another day → 3 distinct.
        $c = CanonicalProduct::factory()->create();
        $this->logDay('2026-08-10', [['nutrients' => ['calories' => 100], 'product' => $c]]);

        $weekly = $this->service->weeklySummary($this->user, Carbon::parse('2026-08-13'));
        $this->assertSame(3, $weekly['food_variety']);
    }

    public function test_meal_regularity_is_days_logged_over_window(): void
    {
        foreach (['2026-08-09', '2026-08-11', '2026-08-13'] as $d) {
            $this->logDay($d, [['nutrients' => ['calories' => 500]]]);
        }

        $summary = $this->service->weeklySummary($this->user, Carbon::parse('2026-08-13'));

        $this->assertSame(3, $summary['meal_regularity']['days_logged']);
        $this->assertSame(7, $summary['meal_regularity']['days']);
        $this->assertSame(round(3 / 7, 3), $summary['meal_regularity']['ratio']);
    }

    public function test_fruit_veg_derived_from_category_else_unknown(): void
    {
        $veg = CanonicalProduct::factory()->create(['category' => 'fresh vegetables']);
        $snack = CanonicalProduct::factory()->create(['category' => 'snacks']);

        $this->logDay('2026-08-13', [
            ['nutrients' => ['calories' => 30], 'product' => $veg],
            ['nutrients' => ['calories' => 200], 'product' => $snack],
        ]);

        $daily = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));
        $fruitVeg = collect($daily['indicators'])->firstWhere('key', 'fruit_veg');
        $this->assertTrue($fruitVeg['known']);
        $this->assertSame(1.0, $fruitVeg['value']);

        // A day with only null-category products cannot derive fruit & veg.
        $unknownProduct = CanonicalProduct::factory()->create(['category' => null]);
        $other = User::factory()->create();
        ConsumptionEvent::factory()->for($other)->create(['consumed_at' => Carbon::parse('2026-08-13 12:00')])
            ->items()->create([
                'canonical_product_id' => $unknownProduct->id,
                'quantity' => 1,
                'unit' => QuantityUnit::Unit,
                'calories' => 100,
            ]);

        $otherDaily = $this->service->dailySummary($other, Carbon::parse('2026-08-13'));
        $otherFruitVeg = collect($otherDaily['indicators'])->firstWhere('key', 'fruit_veg');
        $this->assertFalse($otherFruitVeg['known']);
        $this->assertSame(NutritionAnalyticsService::BAND_UNKNOWN, $otherFruitVeg['band']);
    }

    /**
     * Banding boundaries (brief §9.5). Target is INCLUSIVE = Good.
     */
    #[DataProvider('bandingBoundaries')]
    public function test_indicator_banding_at_boundaries(string $metricKey, float $value, string $expectedBand): void
    {
        $this->logDay('2026-08-13', [['nutrients' => [$metricKey => $value]]]);

        $summary = $this->service->dailySummary($this->user, Carbon::parse('2026-08-13'));
        $indicator = collect($summary['indicators'])->firstWhere('key', $metricKey);

        $this->assertSame($expectedBand, $indicator['band']);
    }

    public static function bandingBoundaries(): array
    {
        // protein target 50 (higher-is-better): OK floor = 50*0.6 = 30.
        // salt limit 6 (lower-is-better): slightly-high above 6*1.25 = 7.5.
        return [
            'protein at target is Good' => ['protein', 50.0, NutritionAnalyticsService::BAND_GOOD],
            'protein just below target is OK' => ['protein', 49.9, NutritionAnalyticsService::BAND_OK],
            'protein at OK floor is OK' => ['protein', 30.0, NutritionAnalyticsService::BAND_OK],
            'protein just below OK floor is Low' => ['protein', 29.9, NutritionAnalyticsService::BAND_LOW],
            'salt at limit is Good' => ['salt', 6.0, NutritionAnalyticsService::BAND_GOOD],
            'salt just above limit is OK' => ['salt', 6.1, NutritionAnalyticsService::BAND_OK],
            'salt at slightly-high threshold is OK' => ['salt', 7.5, NutritionAnalyticsService::BAND_OK],
            'salt above threshold is Slightly high' => ['salt', 7.6, NutritionAnalyticsService::BAND_SLIGHTLY_HIGH],
        ];
    }
}
