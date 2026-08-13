<?php

namespace Tests\Feature;

use App\AI\DataObjects\IdentifiedProduct;
use App\Enums\ResolutionStatus;
use App\Models\CanonicalProduct;
use App\Models\ProductResolutionJob;
use App\Models\User;
use App\Services\ProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProductResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->app->make(ProductResolver::class);
        // Fail loudly if any test path unexpectedly hits the network.
        Http::preventStrayRequests();
    }

    private function detected(array $overrides = []): IdentifiedProduct
    {
        return IdentifiedProduct::fromArray(array_merge([
            'brand' => 'The Gym Kitchen',
            'product_name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayo',
            'pack_size' => '189g',
            'barcode' => null,
            'confidence' => 0.9,
        ], $overrides));
    }

    // --- 1. Barcode ---------------------------------------------------------

    public function test_barcode_matches_a_local_canonical_product(): void
    {
        $product = CanonicalProduct::factory()->create(['gtin' => '5000159407236']);

        $result = $this->resolver->resolve($this->detected(['barcode' => '5000159407236']));

        $this->assertSame(ResolutionStatus::MatchedBarcode, $result->status);
        $this->assertTrue($result->canonicalProduct->is($product));
        $this->assertSame(1.0, $result->confidence);
        $this->assertTrue($result->isCanonicalIdentity());
        $this->assertDatabaseHas('product_resolution_jobs', [
            'id' => $result->resolutionJob->id,
            'status' => 'matched_barcode',
            'matched_product_id' => $product->id,
        ]);
    }

    public function test_barcode_falls_back_to_open_food_facts_and_creates_canonical(): void
    {
        Http::fake([
            'world.openfoodfacts.org/*' => Http::response([
                'status' => 1,
                'code' => '5000159407236',
                'product' => [
                    'product_name' => 'Snickers',
                    'brands' => 'Mars',
                    'quantity' => '48 g',
                    'nutrition_data_per' => '100g',
                    'nutriments' => ['energy-kcal_100g' => 497, 'proteins_100g' => 9.4],
                ],
            ], 200),
        ]);

        $result = $this->resolver->resolve($this->detected(['barcode' => '5000159407236']));

        $this->assertSame(ResolutionStatus::MatchedBarcode, $result->status);
        $this->assertNotNull($result->canonicalProduct);
        $this->assertSame('5000159407236', $result->canonicalProduct->gtin);
        $this->assertDatabaseHas('canonical_products', ['gtin' => '5000159407236', 'name' => 'Snickers']);
    }

    public function test_unresolvable_barcode_falls_through_to_name_matching(): void
    {
        Http::fake([
            'world.openfoodfacts.org/*' => Http::response(['status' => 0], 200),
        ]);
        $product = CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'The Gym Kitchen',
            'name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayo',
            'pack_size_value' => 189,
            'pack_size_unit' => 'g',
        ]);

        $result = $this->resolver->resolve($this->detected(['barcode' => '9999999999999']));

        // OFF miss on the barcode → falls through and finds the exact local match.
        $this->assertSame(ResolutionStatus::MatchedExact, $result->status);
        $this->assertTrue($result->canonicalProduct->is($product));
    }

    // --- 2. Exact -----------------------------------------------------------

    public function test_exact_brand_name_variant_size_match(): void
    {
        $product = CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'THE GYM KITCHEN', // different case → still exact after normalisation
            'name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayo',
            'pack_size_value' => 189,
            'pack_size_unit' => 'g',
        ]);

        $result = $this->resolver->resolve($this->detected());

        $this->assertSame(ResolutionStatus::MatchedExact, $result->status);
        $this->assertTrue($result->canonicalProduct->is($product));
        $this->assertSame(1.0, $result->confidence);
    }

    public function test_different_variant_is_not_an_exact_match(): void
    {
        CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'The Gym Kitchen',
            'name' => 'High Protein Katsu Chicken',
            'variant' => 'With Mayo', // different variant
            'pack_size_value' => 189,
            'pack_size_unit' => 'g',
        ]);

        $result = $this->resolver->resolve($this->detected());

        $this->assertNotSame(ResolutionStatus::MatchedExact, $result->status);
    }

    // --- 3. Fuzzy -----------------------------------------------------------

    public function test_high_similarity_auto_matches_fuzzily(): void
    {
        $product = CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'The Gym Kitchen',
            'name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayonnaise', // close but not exact
            'pack_size_value' => 189,
            'pack_size_unit' => 'g',
        ]);

        $result = $this->resolver->resolve($this->detected());

        $this->assertSame(ResolutionStatus::MatchedFuzzy, $result->status);
        $this->assertTrue($result->canonicalProduct->is($product));
        $this->assertGreaterThanOrEqual(ProductResolver::FUZZY_AUTO_MATCH_THRESHOLD, $result->matchScore);
        $this->assertTrue($result->isCanonicalIdentity());
    }

    public function test_middling_similarity_is_a_suggestion_not_identity(): void
    {
        $product = CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'The Gym Kitchen',
            'name' => 'Peri Peri Chicken Rice',
            'variant' => null,
            'pack_size_value' => 200,
            'pack_size_unit' => 'g',
        ]);

        $result = $this->resolver->resolve($this->detected());

        $this->assertSame(ResolutionStatus::Suggestion, $result->status);
        $this->assertTrue($result->canonicalProduct->is($product));
        $this->assertFalse($result->isCanonicalIdentity(), 'A suggestion must not auto-become canonical identity.');
        $this->assertTrue($result->isSuggestion());
        $this->assertGreaterThanOrEqual(ProductResolver::FUZZY_SUGGESTION_THRESHOLD, $result->matchScore);
        $this->assertLessThan(ProductResolver::FUZZY_AUTO_MATCH_THRESHOLD, $result->matchScore);
    }

    // --- 4. Unknown ---------------------------------------------------------

    public function test_no_match_is_needs_research(): void
    {
        CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'Completely Different Co',
            'name' => 'Sparkling Water',
            'variant' => null,
        ]);

        $result = $this->resolver->resolve($this->detected());

        $this->assertSame(ResolutionStatus::NeedsResearch, $result->status);
        $this->assertNull($result->canonicalProduct);
        $this->assertTrue($result->needsResearch());
    }

    public function test_empty_catalogue_is_needs_research(): void
    {
        $result = $this->resolver->resolve($this->detected());

        $this->assertSame(ResolutionStatus::NeedsResearch, $result->status);
        $this->assertNull($result->canonicalProduct);
    }

    // --- Audit trail --------------------------------------------------------

    public function test_every_resolution_writes_a_job_with_detected_fields_and_model_info(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve(
            $this->detected(),
            $user,
            ['model_provider' => 'openrouter', 'model_name' => 'openai/gpt-4o-mini', 'latency_ms' => 850],
        );

        $job = $result->resolutionJob;
        $this->assertInstanceOf(ProductResolutionJob::class, $job);
        $this->assertSame($user->id, $job->user_id);
        $this->assertSame('openrouter', $job->model_provider);
        $this->assertSame('openai/gpt-4o-mini', $job->model_name);
        $this->assertSame(850, $job->latency_ms);
        $this->assertSame('The Gym Kitchen', $job->detected_fields['brand']);
        $this->assertEquals(0.9, (float) $job->detection_confidence);
    }
}
