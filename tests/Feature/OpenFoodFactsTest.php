<?php

namespace Tests\Feature;

use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
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
                'nutriments' => [
                    'energy-kcal_100g' => 497,
                    'proteins_100g' => 9.4,
                    'carbohydrates_100g' => 57,
                    'sugars_100g' => 51,
                    'fat_100g' => 24,
                    'saturated-fat_100g' => 9,
                    // fiber_100g and salt_100g intentionally omitted (not stated)
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

    public function test_client_returns_a_product_on_found(): void
    {
        $this->fakeFound();

        $product = (new OpenFoodFactsClient)->fetchByBarcode(self::BARCODE);

        $this->assertNotNull($product);
        $this->assertSame('Snickers', $product->productName);
        $this->assertSame('Mars', $product->brand); // first of the comma list
        $this->assertSame(497.0, $product->nutriment('energy-kcal_100g'));
        $this->assertNull($product->nutriment('fiber_100g')); // not stated
    }

    public function test_nutriment_rejects_out_of_range_or_negative_values(): void
    {
        // OFF is crowd-sourced and full of data-entry errors. The nutrient columns
        // are decimal(8,2) (Postgres rejects >= 10^6), so an impossible per-100g
        // value must be treated as unknown (null), never stored — otherwise a
        // single bad product 500s the whole scan.
        $product = OffProduct::fromApi('123', [
            'product_name' => 'Bad data',
            'nutriments' => [
                'energy-kcal_100g' => 123456789, // absurd → null
                'proteins_100g' => -5,           // negative → null
                'salt_100g' => 1.2,              // valid → kept
            ],
        ]);

        $this->assertNull($product->nutriment('energy-kcal_100g'));
        $this->assertNull($product->nutriment('proteins_100g'));
        $this->assertSame(1.2, $product->nutriment('salt_100g'));
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

        $version = $product->versions()->firstOrFail();
        $this->assertSame(ServingBasis::Per100g, $version->serving_basis);
        $this->assertEquals(497.0, (float) $version->calories);
        $this->assertEquals(9.4, (float) $version->protein);

        // Unknown nutrients stored as NULL, never fabricated to 0 (brief §2.1).
        $this->assertNull($version->fibre);
        $this->assertNull($version->salt);

        $source = $version->sources()->firstOrFail();
        $this->assertSame(SourceType::OpenFoodFacts, $source->source_type);
        $this->assertStringContainsString(self::BARCODE, $source->source_url);
        $this->assertStringContainsString('Not stated', $source->evidence_summary);
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
