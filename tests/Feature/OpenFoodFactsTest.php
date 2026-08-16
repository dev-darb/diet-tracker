<?php

namespace Tests\Feature;

use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
use App\Nutrition\NutrientRegistry;
use App\Services\OpenFoodFacts\OffProduct;
use App\Services\OpenFoodFacts\OpenFoodFactsClient;
use App\Services\OpenFoodFacts\OpenFoodFactsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenFoodFactsTest extends TestCase
{
    use RefreshDatabase;

    private const BARCODE = '5000159407236';

    /** A realistic (trimmed) OFF v2 payload with fibre + salt DELIBERATELY absent. */
    private function foundPayload(): array
    {
        return [
            'status' => 1,
            'status_verbose' => 'product found',
            'code' => self::BARCODE,
            'product' => [
                'code' => self::BARCODE,
                'product_name' => 'Snickers',
                'brands' => 'Mars, Snickers',
                'quantity' => '48 g',
                'serving_size' => '48 g',
                'nutrition_data_per' => '100g',
                'ingredients_text' => 'Milk chocolate, peanuts, sugar',
                'allergens_tags' => ['en:milk', 'en:nuts', 'en:peanuts'],
                'categories_tags' => ['en:snacks', 'en:sweet-snacks', 'en:chocolate-bars'],
                'image_front_small_url' => 'https://images.openfoodfacts.org/snickers.200.jpg',
                'nutriments' => [
                    'energy-kcal_100g' => 497,
                    'proteins_100g' => 9.4,
                    'carbohydrates_100g' => 57,
                    'sugars_100g' => 51,
                    'fat_100g' => 24,
                    'saturated-fat_100g' => 9,
                    // fiber_100g and salt_100g intentionally omitted (not stated)
                    'calcium_100g' => 0.108,    // OFF states minerals in GRAMS
                    'iron_100g' => 0.0021,
                ],
            ],
        ];
    }

    private function fakeFound(): void
    {
        Http::fake([
            'world.openfoodfacts.org/*' => Http::response($this->foundPayload(), 200),
        ]);
    }

    private function fakeNotFound(): void
    {
        Http::fake([
            'world.openfoodfacts.org/*' => Http::response([
                'status' => 0,
                'status_verbose' => 'product not found',
                'code' => self::BARCODE,
            ], 200),
        ]);
    }

    // --- Client -------------------------------------------------------------

    public function test_client_sends_a_descriptive_user_agent(): void
    {
        $this->fakeFound();

        (new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v2/product/'.self::BARCODE.'.json')
                && str_starts_with($request->header('User-Agent')[0] ?? '', 'DietTracker/');
        });
    }

    /**
     * AUDIT D1. The request must ask for every field OffProduct reads. It used to
     * omit the category and image keys while the mapper read them, so the API
     * never returned them — every product arrived with no category (starving the
     * Foody Score plant pillar) and no photo (leaving the image markup on pantry
     * rows, scan cards and the chef permanently dark).
     */
    public function test_client_requests_every_field_the_mapper_reads(): void
    {
        $this->fakeFound();

        (new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE);

        Http::assertSent(function ($request) {
            $fields = $request->data()['fields'] ?? '';

            foreach ([
                'product_name', 'brands', 'quantity', 'product_quantity', 'nutriments',
                'serving_size', 'categories_tags', 'image_front_small_url',
            ] as $field) {
                $this->assertStringContainsString($field, $fields, "OFF request is missing `{$field}`");
            }

            return true;
        });
    }

    public function test_client_returns_a_product_on_found(): void
    {
        $this->fakeFound();

        $product = (new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE);

        $this->assertNotNull($product);
        $this->assertSame('Snickers', $product->productName);
        $this->assertSame('Mars', $product->brand); // first of the comma list
        $this->assertSame(497.0, $product->per100gNutrients()['calories']);
        $this->assertNull($product->per100gNutrients()['fibre']); // not stated
    }

    public function test_impossible_values_are_treated_as_unknown_never_stored(): void
    {
        // OFF is crowd-sourced and full of data-entry errors. A per-100g figure
        // beyond physical possibility is a typo, not a measurement, and must
        // become unknown rather than a stored lie (or a decimal overflow).
        $product = OffProduct::fromApi('123', [
            'product_name' => 'Bad data',
            'nutriments' => [
                'energy-kcal_100g' => 123456789, // absurd → unknown
                'proteins_100g' => -5,           // negative → unknown
                'salt_100g' => 1.2,              // valid → kept
            ],
        ]);

        $values = $product->per100gNutrients();

        $this->assertNull($values['calories']);
        $this->assertNull($values['protein']);
        $this->assertSame(1.2, $values['salt']);
    }

    public function test_client_returns_null_on_not_found(): void
    {
        $this->fakeNotFound();

        $this->assertNull((new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE));
    }

    public function test_client_returns_null_on_network_error_without_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->assertNull((new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE));
    }

    // --- Reading nutrition (audit D2, D3) -----------------------------------

    /**
     * AUDIT D2. UK and EU labels are entered in kilojoules, so a great many OFF
     * records carry no kcal figure at all. Reading only `energy-kcal_100g` made
     * those products calorie-less — and because unknowns correctly propagate
     * through a day's total, one of them blanked the whole day.
     */
    public function test_energy_stated_only_in_kilojoules_still_yields_calories(): void
    {
        $product = OffProduct::fromApi('123', [
            'product_name' => 'Kilojoule only',
            'nutriments' => ['energy-kj_100g' => 1462],
        ]);

        $nutrition = $product->nutrition();

        $this->assertEqualsWithDelta(349.5, $nutrition['values']['calories'], 0.5);
        // And the derivation is recorded, so provenance can say where it came from.
        $this->assertSame('energy-kj_100g', $nutrition['derived']['calories']);
    }

    /** AUDIT D3. Salt stated as sodium is the same fact: salt = sodium x 2.5. */
    public function test_salt_stated_only_as_sodium_is_derived(): void
    {
        $product = OffProduct::fromApi('123', [
            'product_name' => 'Sodium only',
            'nutriments' => ['sodium_100g' => 0.4],
        ]);

        $nutrition = $product->nutrition();

        $this->assertSame(1.0, $nutrition['values']['salt']);
        $this->assertSame('sodium_100g', $nutrition['derived']['salt']);
    }

    /**
     * Some records state figures per serving without their per-100 counterparts.
     * Renormalising a stated per-serving figure is arithmetic on real data — but
     * only when the serving size is known, otherwise the figure stays unknown.
     */
    public function test_per_serving_figures_are_renormalised_when_the_serving_size_is_known(): void
    {
        $product = OffProduct::fromApi('123', [
            'product_name' => 'Per serving only',
            'serving_size' => '50 g',
            'nutriments' => ['energy-kcal_serving' => 100, 'proteins_serving' => 5],
        ]);

        $nutrition = $product->nutrition();

        $this->assertSame(200.0, $nutrition['values']['calories']);
        $this->assertSame(10.0, $nutrition['values']['protein']);
        $this->assertSame('energy-kcal_serving', $nutrition['derived']['calories']);
    }

    public function test_per_serving_figures_stay_unknown_without_a_serving_size(): void
    {
        $product = OffProduct::fromApi('123', [
            'product_name' => 'No serving size',
            'nutriments' => ['energy-kcal_serving' => 100],
        ]);

        $this->assertNull($product->per100gNutrients()['calories']);
    }

    /** Micronutrients arrive from OFF in grams and are stored the way a label quotes them. */
    public function test_micronutrients_are_converted_out_of_grams(): void
    {
        $this->fakeFound();

        $product = (new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE);
        $values = $product->per100gNutrients();

        $this->assertSame(108.0, $values['calcium']);  // 0.108 g -> 108 mg
        $this->assertSame(2.1, $values['iron']);       // 0.0021 g -> 2.1 mg
        $this->assertNull($values['vitamin_d']);       // not stated -> unknown, never 0
    }

    // --- Importer -----------------------------------------------------------

    public function test_importer_creates_canonical_version_and_source(): void
    {
        $this->fakeFound();

        $importer = $this->app->make(OpenFoodFactsImporter::class);
        $product = $importer->importByBarcode(self::BARCODE);

        $this->assertNotNull($product);
        $this->assertSame(self::BARCODE, $product->gtin);
        $this->assertSame('Mars', $product->brand);
        $this->assertSame('Snickers', $product->name);
        $this->assertEquals(48.0, (float) $product->pack_size_value);
        $this->assertSame('g', $product->pack_size_unit);

        // D1: the category and the image now actually arrive.
        $this->assertSame('chocolate bars', $product->category);
        $this->assertSame('https://images.openfoodfacts.org/snickers.200.jpg', $product->primary_image_path);

        $version = $product->versions()->firstOrFail();
        $this->assertSame(ServingBasis::Per100g, $version->serving_basis);
        $this->assertEquals(497.0, (float) $version->calories);
        $this->assertEquals(9.4, (float) $version->protein);
        $this->assertEquals(108.0, (float) $version->calcium);

        // Unknown nutrients stored as NULL, never fabricated to 0 (brief §2.1).
        $this->assertNull($version->fibre);
        $this->assertNull($version->salt);
        $this->assertNull($version->vitamin_d);

        $source = $version->sources()->firstOrFail();
        $this->assertSame(SourceType::OpenFoodFacts, $source->source_type);
        $this->assertStringContainsString(self::BARCODE, $source->source_url);
        $this->assertStringContainsString('Not stated', $source->evidence_summary);
        $this->assertStringContainsString(
            'Micronutrients stated: 2 of '.count(NutrientRegistry::microKeys()),
            $source->evidence_summary,
        );
    }

    /**
     * AUDIT D4/D5, end to end. A 1 kg bag whose serving reads "1 portion (75g)"
     * used to import as a 1-gram pack with a 1-"portion" serving, and eating the
     * whole bag logged 0.78 g of carbohydrate instead of 780 g.
     */
    public function test_awkward_pack_and_serving_strings_import_as_real_measures(): void
    {
        Http::fake(['world.openfoodfacts.org/*' => Http::response([
            'status' => 1,
            'product' => [
                'code' => '5051234567890',
                'product_name' => 'Basmati rice',
                'brands' => 'Tesco',
                'quantity' => '1kg',
                'serving_size' => '1 portion (75g)',
                'nutriments' => ['energy-kcal_100g' => 349, 'carbohydrates_100g' => 78],
            ],
        ], 200)]);

        $product = $this->app->make(OpenFoodFactsImporter::class)->importByBarcode('5051234567890');

        $this->assertEquals(1000.0, (float) $product->pack_size_value);
        $this->assertSame('g', $product->pack_size_unit);

        $version = $product->versions()->firstOrFail();
        $this->assertEquals(75.0, (float) $version->serving_size_value);
        $this->assertSame('g', $version->serving_size_unit);
    }

    /** A pack size we cannot read stays unknown rather than becoming a wrong number. */
    public function test_an_unreadable_pack_size_stays_unknown(): void
    {
        $product = OffProduct::fromApi('123', [
            'product_name' => 'Loose apples',
            'quantity' => 'a few',
            'serving_size' => '1 apple',
        ]);

        $this->assertNull($product->packSize());
        $this->assertNull($product->servingSize());
    }

    public function test_importer_is_idempotent_on_gtin(): void
    {
        $this->fakeFound();
        $importer = $this->app->make(OpenFoodFactsImporter::class);

        $first = $importer->importByBarcode(self::BARCODE);
        $second = $importer->importByBarcode(self::BARCODE);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CanonicalProduct::where('gtin', self::BARCODE)->count());
        $this->assertSame(1, $first->versions()->count());
    }

    public function test_importer_returns_null_when_off_has_no_match(): void
    {
        $this->fakeNotFound();

        $this->assertNull($this->app->make(OpenFoodFactsImporter::class)->importByBarcode(self::BARCODE));
        $this->assertDatabaseCount('canonical_products', 0);
    }
}
