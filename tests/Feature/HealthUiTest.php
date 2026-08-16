<?php

namespace Tests\Feature;

use App\Enums\ConsumptionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HealthUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    private function logDay(User $user, string $date, array $nutrients, ?CanonicalProduct $product = null): void
    {
        ConsumptionEvent::factory()->for($user)->create([
            'type' => ConsumptionType::Single,
            'consumed_at' => Carbon::parse($date.' 12:00:00'),
            // Real writes snapshot totals on BOTH event and item; day totals
            // now read the event (so eating-out entries count), so mirror it.
            ...array_merge(array_fill_keys([
                'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
            ], 0.0), $nutrients),
        ])->items()->create([
            'canonical_product_id' => $product?->id,
            'quantity' => 1,
            'unit' => QuantityUnit::Unit,
            ...array_merge(array_fill_keys([
                'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
            ], 0.0), $nutrients),
        ]);
    }

    /** A week of logged days ending today, seen from a fixed evening. */
    private function logSteadyWeek(float $todayCalories = 2250): void
    {
        $this->travelTo(Carbon::parse('2026-08-16 21:00:00'));

        for ($i = 7; $i >= 1; $i--) {
            $this->logDay($this->user, now()->subDays($i)->toDateString(), [
                'calories' => 2250, 'protein' => 100, 'fibre' => 30, 'saturated_fat' => 15, 'salt' => 4,
            ]);
        }

        $this->logDay($this->user, now()->toDateString(), [
            'calories' => $todayCalories, 'protein' => 100, 'fibre' => 30, 'saturated_fat' => 15, 'salt' => 4,
        ]);
    }

    public function test_a_finished_day_closes_on_home_with_its_streak(): void
    {
        $this->logSteadyWeek();

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Day closed')
            ->assertSee('8 days running');
    }

    public function test_energy_inside_the_goal_band_lights_the_readout(): void
    {
        // 2250 kcal against the generic 2250 target: dead inside the
        // general-health full band, so the reading earns the good lamp.
        $this->logSteadyWeek(2250);

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('value-settle data-xl text-good', escape: false);
    }

    public function test_energy_outside_the_goal_band_does_not_light_the_readout(): void
    {
        // Well over the band — an honest reading, no reward lamp.
        $this->logSteadyWeek(3400);

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertDontSee('value-settle data-xl text-good', escape: false);
    }

    public function test_home_score_reads_as_building_with_thin_history(): void
    {
        // One logged day is not enough history for a confident number — the
        // headline says so instead of judging (spec §13).
        $this->logDay($this->user, now()->toDateString(), ['calories' => 900]);

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Foody Score')
            ->assertSee('Building')
            ->assertSee('firm read');
    }

    public function test_home_shows_score_headline_and_today_snapshot_with_data(): void
    {
        $product = CanonicalProduct::factory()->create(['category' => 'fresh vegetables']);
        $this->logDay($this->user, now()->toDateString(), [
            'calories' => 1820, 'protein' => 106, 'fibre' => 19, 'saturated_fat' => 30, 'salt' => 3,
        ], $product);

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Foody Score')
            ->assertSee('Today')
            ->assertSee('1,820')
            ->assertSee('Protein')
            ->assertSee('Fibre')
            ->assertSee('Last 7 days');
    }

    public function test_home_shows_empty_snapshot_without_data(): void
    {
        // A brand-new user: the kcal readout sits idle (----, never a zero)
        // and the read explains the score is still building.
        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('----')
            ->assertSee('firm read');
    }

    public function test_health_shows_weekly_averages_variety_and_trend(): void
    {
        $veg = CanonicalProduct::factory()->create(['category' => 'vegetables']);
        $snack = CanonicalProduct::factory()->create(['category' => 'snacks']);

        // Previous week — lower protein so the current week trends up.
        foreach (['2026-08-01', '2026-08-02', '2026-08-04', '2026-08-06'] as $d) {
            $this->logDay($this->user, $d, ['calories' => 2000, 'protein' => 90], $snack);
        }
        // Current week — distinct foods + higher protein.
        foreach (['2026-08-08', '2026-08-10', '2026-08-13'] as $d) {
            $this->logDay($this->user, $d, ['calories' => 2100, 'protein' => 110], $veg);
        }
        $this->logDay($this->user, '2026-08-12', ['calories' => 2100, 'protein' => 110], $snack);

        Carbon::setTestNow(Carbon::parse('2026-08-13 18:00:00'));

        $this->actingAs($this->user)->get('/health')
            ->assertOk()
            ->assertSee('This week')
            ->assertSee('KCAL/DAY')
            ->assertSee('distinct')
            ->assertSee('days')
            ->assertSee('VS LAST WK')
            ->assertSee('Weekly indicators')
                        ->assertDontSee('Standby');

        Carbon::setTestNow();
    }

    public function test_health_shows_placeholder_without_data(): void
    {
        $this->actingAs($this->user)->get('/health')
            ->assertOk()
            ->assertSee('No readings yet');
    }
}
