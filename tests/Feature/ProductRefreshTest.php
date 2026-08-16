<?php

namespace Tests\Feature;

use App\Enums\ProductVerificationStatus;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\User;
use App\Services\ConsumptionService;
use App\Services\OpenFoodFacts\ProductRefresher;
use App\Services\PantryNutritionService;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The backfill's contract: repair the product, freeze the history.
 *
 * This is the founder's decision (Aug 2026) made testable. Products carry the
 * damage the audit found and must be corrected; days already eaten and scored
 * must not move underneath the user, even to become more accurate.
 */
class ProductRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const BARCODE = '5051234567890';

    /** The product as the fixed importer would read it today. */
    private function fakeCorrectedSource(): void
    {
        Http::fake(['world.openfoodfacts.org/*' => Http::response([
            'status' => 1,
            'product' => [
                'code' => self::BARCODE,
                'product_name' => 'Basmati rice',
                'brands' => 'Tesco',
                'quantity' => '1kg',
                'serving_size' => '1 portion (75g)',
                'categories_tags' => ['en:plant-based-foods', 'en:rices'],
                'image_front_small_url' => 'https://images.openfoodfacts.org/rice.200.jpg',
                'nutriments' => [
                    'energy-kj_100g' => 1462,
                    'proteins_100g' => 8.5,
                    'carbohydrates_100g' => 78,
                    'fat_100g' => 1.2,
                ],
            ],
        ], 200)]);
    }

    /** A product in the shape the pre-audit importer left behind. */
    private function damagedProduct(): CanonicalProduct
    {
        $product = CanonicalProduct::factory()->create([
            'gtin' => self::BARCODE,
            'brand' => 'Tesco',
            'name' => 'Basmati rice',
            'category' => null,                 // the field list never asked for it
            'primary_image_path' => null,       // nor for this
            'pack_size_value' => 1,             // "1kg" read as one of something
            'pack_size_unit' => 'kg',
        ]);

        $product->versions()->create([
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => 1,
            'serving_size_unit' => 'portion',   // not a mass at all
            'calories' => null,                 // kilojoule-only record lost it
            'protein' => 8.5,
            'carbs' => 78,
            'fat' => 1.2,
            'effective_from' => now()->subMonth(),
            'status' => ProductVerificationStatus::AutoVerified,
        ]);

        return $product;
    }

    public function test_it_repairs_identity_and_metadata_in_place(): void
    {
        $this->fakeCorrectedSource();
        $product = $this->damagedProduct();

        $this->app->make(ProductRefresher::class)->refresh($product);

        $product->refresh();

        $this->assertSame('rices', $product->category);
        $this->assertSame('https://images.openfoodfacts.org/rice.200.jpg', $product->primary_image_path);
        $this->assertEquals(1000.0, (float) $product->pack_size_value);
        $this->assertSame('g', $product->pack_size_unit);
    }

    public function test_corrected_nutrition_arrives_as_a_new_version_not_an_edit(): void
    {
        $this->fakeCorrectedSource();
        $product = $this->damagedProduct();
        $original = $product->versions()->firstOrFail();

        $this->app->make(ProductRefresher::class)->refresh($product);

        $this->assertSame(2, $product->versions()->count());

        // The old version is retired, and every figure on it is untouched.
        $original->refresh();
        $this->assertSame(ProductVerificationStatus::Superseded, $original->status);
        $this->assertNull($original->calories);
        $this->assertEquals(1.0, (float) $original->serving_size_value);
        $this->assertSame('portion', $original->serving_size_unit);

        $current = $this->app->make(PantryNutritionService::class)->currentVersion($product->refresh());
        $this->assertEqualsWithDelta(349.5, (float) $current->calories, 1.0);
        $this->assertEquals(75.0, (float) $current->serving_size_value);
        $this->assertSame('g', $current->serving_size_unit);
    }

    /**
     * THE GUARANTEE. A day already eaten keeps the figures it was recorded with,
     * even though the product's data has since been corrected. The snapshot is
     * what you ate; correcting the product does not change what you ate.
     */
    public function test_a_day_already_eaten_does_not_move(): void
    {
        $user = User::factory()->onboarded()->create();
        $product = $this->damagedProduct();

        $item = $this->app->make(PantryService::class)
            ->purchase($user, $product, 500.0, QuantityUnit::Gram);

        $event = $this->app->make(ConsumptionService::class)
            ->consumePantryItem($user, $item, 100.0);

        $before = [
            'calories' => $event->calories,
            'protein' => (float) $event->protein,
            'carbs' => (float) $event->carbs,
        ];

        $this->fakeCorrectedSource();
        $this->app->make(ProductRefresher::class)->refresh($product);

        $event->refresh();

        $this->assertNull($event->calories, 'A historical snapshot must not gain figures it never had.');
        $this->assertSame($before['protein'], (float) $event->protein);
        $this->assertSame($before['carbs'], (float) $event->carbs);

        // The line still points at the version that was current when it was eaten.
        $line = $event->items()->firstOrFail();
        $this->assertSame(ProductVerificationStatus::Superseded, $line->productVersion->status);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->fakeCorrectedSource();
        $product = $this->damagedProduct();

        $outcome = $this->app->make(ProductRefresher::class)->refresh($product, dryRun: true);

        $this->assertTrue($outcome->identityChanged);
        $this->assertTrue($outcome->nutritionChanged);
        $this->assertNotEmpty($outcome->changes);

        $product->refresh();
        $this->assertNull($product->category);
        $this->assertSame(1, $product->versions()->count());
    }

    public function test_a_product_already_correct_is_left_alone(): void
    {
        $this->fakeCorrectedSource();
        $product = $this->damagedProduct();

        $refresher = $this->app->make(ProductRefresher::class);
        $refresher->refresh($product);

        // Running it again must not churn out another identical version.
        $second = $refresher->refresh($product->refresh());

        $this->assertFalse($second->nutritionChanged);
        $this->assertFalse($second->identityChanged);
        $this->assertSame(2, $product->versions()->count());
    }

    public function test_the_command_reports_without_writing_on_a_dry_run(): void
    {
        $this->fakeCorrectedSource();
        $this->damagedProduct();

        $this->artisan('foody:refresh-products --dry-run')
            ->expectsOutputToContain('nothing will be written')
            ->assertSuccessful();

        $this->assertNull(CanonicalProduct::where('gtin', self::BARCODE)->firstOrFail()->category);
    }
}
