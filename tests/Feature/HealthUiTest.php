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
        ])->items()->create([
            'canonical_product_id' => $product?->id,
            'quantity' => 1,
            'unit' => QuantityUnit::Unit,
            ...array_merge(array_fill_keys([
                'calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt',
            ], 0.0), $nutrients),
        ]);
    }

    public function test_home_shows_today_snapshot_and_indicators_with_data(): void
    {
        $product = CanonicalProduct::factory()->create(['category' => 'fresh vegetables']);
        $this->logDay($this->user, now()->toDateString(), [
            'calories' => 1820, 'protein' => 106, 'fibre' => 19, 'saturated_fat' => 30, 'salt' => 3,
        ], $product);

        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('1,820')
            ->assertSee('How today looks')
            ->assertSee('Protein')
            ->assertSee('not personalised medical');
    }

    public function test_home_shows_empty_snapshot_without_data(): void
    {
        $this->actingAs($this->user)->get('/home')
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('once you log what you eat');
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
            ->assertSee('Average intake')
            ->assertSee('distinct foods')
            ->assertSee('days logged')
            ->assertSee('vs last week')
            ->assertSee('Weekly indicators')
            ->assertSee('Trends')
            ->assertSee('not personalised medical');

        Carbon::setTestNow();
    }

    public function test_health_shows_placeholder_without_data(): void
    {
        $this->actingAs($this->user)->get('/health')
            ->assertOk()
            ->assertSee('No insights yet');
    }
}
