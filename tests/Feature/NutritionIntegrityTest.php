<?php

namespace Tests\Feature;

use App\Enums\ProductVerificationStatus;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\User;
use App\Nutrition\NutrientRegistry;
use App\Services\ConsumptionService;
use App\Services\OpenFoodFacts\OpenFoodFactsImporter;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * End-to-end guards for the food-intelligence audit (Aug 2026): barcode in,
 * canonical product out, eaten, nutrition recorded.
 *
 * These are the acceptance cases the audit was written against. Each one failed
 * before the tranche, and each one failed silently — the app returned a number,
 * it was simply the wrong number by three orders of magnitude.
 */
class NutritionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->onboarded()->create();
    }

    /**
     * A realistic UK grocery record: net contents written as "1kg", a serving
     * written as "1 portion (75g)", energy stated only in kilojoules, salt stated
     * only as sodium, and a handful of micronutrients in grams.
     */
    private function fakeRice(): void
    {
        Http::fake(['world.openfoodfacts.org/*' => Http::response([
            'status' => 1,
            'product' => [
                'code' => '5051234567890',
                'product_name' => 'Basmati rice',
                'brands' => 'Tesco',
                'quantity' => '1kg',
                'serving_size' => '1 portion (75g)',
                'categories_tags' => ['en:plant-based-foods', 'en:cereals-and-potatoes', 'en:rices'],
                'image_front_small_url' => 'https://images.openfoodfacts.org/rice.200.jpg',
                'nutriments' => [
                    'energy-kj_100g' => 1462,
                    'proteins_100g' => 8.5,
                    'carbohydrates_100g' => 78,
                    'fat_100g' => 1.2,
                    'sodium_100g' => 0.004,
                    'iron_100g' => 0.0012,
                    // fibre, sugars, saturates and every other micro unstated.
                ],
            ],
        ], 200)]);
    }

    /**
     * ACCEPTANCE 1 — a barcode resolves to the right product, image, package and
     * serving metadata, and nutrition.
     */
    public function test_a_barcode_resolves_to_a_complete_canonical_product(): void
    {
        $this->fakeRice();

        $product = $this->app->make(OpenFoodFactsImporter::class)->importByBarcode('5051234567890');
        $version = $product->versions()->firstOrFail();

        $this->assertSame('Tesco', $product->brand);
        $this->assertSame('rices', $product->category);
        $this->assertSame('https://images.openfoodfacts.org/rice.200.jpg', $product->primary_image_path);

        // A kilogram is a thousand grams, not one gram.
        $this->assertEquals(1000.0, (float) $product->pack_size_value);
        $this->assertSame('g', $product->pack_size_unit);

        // "1 portion (75g)" is seventy-five grams, not one "portion".
        $this->assertEquals(75.0, (float) $version->serving_size_value);
        $this->assertSame('g', $version->serving_size_unit);

        // Kilojoules are energy; sodium is salt.
        $this->assertEqualsWithDelta(349.5, (float) $version->calories, 1.0);
        $this->assertEqualsWithDelta(0.01, (float) $version->salt, 0.001);
        $this->assertEqualsWithDelta(1.2, (float) $version->iron, 0.001);
    }

    /**
     * ACCEPTANCE 2 — nutrition the source does not state stays unknown. This is
     * the rule the whole data model is built to protect: an understated total is
     * worse than an admitted gap, because only one of them lies.
     */
    public function test_unstated_nutrition_stays_unknown_rather_than_zero(): void
    {
        $this->fakeRice();

        $version = $this->app->make(OpenFoodFactsImporter::class)
            ->importByBarcode('5051234567890')
            ->versions()->firstOrFail();

        $this->assertNull($version->fibre);
        $this->assertNull($version->sugars);
        $this->assertNull($version->saturated_fat);
        $this->assertNull($version->calcium);
        $this->assertNull($version->vitamin_d);

        // And a stated figure of genuinely zero is still a known zero.
        $this->assertNotNull($version->calories);
    }

    /**
     * THE HEADLINE REGRESSION. Eating a whole 1 kg bag used to log 0.78 g of
     * carbohydrate: the pack parsed as "1 kg" was multiplied as one gram, and
     * nothing downstream checked the unit. Off by a factor of a thousand, with
     * no error anywhere.
     */
    public function test_eating_a_whole_kilogram_pack_logs_a_kilogram_of_nutrition(): void
    {
        $this->fakeRice();

        $product = $this->app->make(OpenFoodFactsImporter::class)->importByBarcode('5051234567890');

        $item = $this->app->make(PantryService::class)
            ->purchase($this->user, $product, 1.0, QuantityUnit::Pack);

        $event = $this->app->make(ConsumptionService::class)
            ->consumePantryItem($this->user, $item, 1.0);

        // 78 g of carbohydrate per 100 g, across 1000 g.
        $this->assertEqualsWithDelta(780.0, (float) $event->carbs, 0.5);
        $this->assertEqualsWithDelta(85.0, (float) $event->protein, 0.5);
        $this->assertEqualsWithDelta(3495.0, (float) $event->calories, 10.0);

        // Unknowns survive the journey intact — never summed into a zero.
        $this->assertNull($event->fibre);
    }

    /** A serving stated in servings is not a serving stated in grams. */
    public function test_a_non_metric_serving_size_yields_unknown_not_a_wrong_number(): void
    {
        $product = CanonicalProduct::factory()->create([
            'pack_size_value' => null,
            'pack_size_unit' => null,
        ]);

        // The shape a pre-audit import left behind: a count, not a measure.
        $product->versions()->create([
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => 1,
            'serving_size_unit' => 'portion',
            'calories' => 400,
            'effective_from' => now(),
            'status' => ProductVerificationStatus::AutoVerified,
        ]);

        $item = $this->app->make(PantryService::class)
            ->purchase($this->user, $product, 1.0, QuantityUnit::Unit);

        $event = $this->app->make(ConsumptionService::class)
            ->consumePantryItem($this->user, $item, 1.0);

        // Before the unit contract this recorded 4 kcal — one gram of the food.
        $this->assertNull($event->calories);
    }

    /** Every registry nutrient has a column on all three nutrition tables. */
    public function test_the_schema_carries_every_registered_nutrient(): void
    {
        foreach (['product_versions', 'consumption_events', 'consumption_items'] as $table) {
            foreach (NutrientRegistry::keys() as $key) {
                $this->assertTrue(
                    Schema::hasColumn($table, $key),
                    "`{$table}` is missing the `{$key}` column",
                );
            }
        }
    }
}
