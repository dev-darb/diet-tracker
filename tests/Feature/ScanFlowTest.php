<?php

namespace Tests\Feature;

use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\ProductResolutionJob;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Tests\TestCase;

/**
 * Scan flow UI (BUILD_PLAN J2.5; brief §7.2–§7.7). All AI/network is faked
 * (Prism::fake + Http::fake) — no live keys, no network.
 */
class ScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->onboarded()->create();
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('product.jpg');
    }

    private function fakeOff(array $product): void
    {
        Http::fake([
            'world.openfoodfacts.org/*' => Http::response([
                'status' => 1,
                'code' => $product['code'] ?? '5000159407236',
                'product' => $product,
            ], 200),
        ]);
    }

    private function fakeIdentification(array $structured): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured($structured)
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(120, 45))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);
    }

    // --- 1. Barcode fast path, end to end ----------------------------------

    public function test_barcode_path_resolves_confirms_and_adds_to_pantry(): void
    {
        $this->fakeOff([
            'code' => '5000159407236',
            'product_name' => 'Snickers',
            'brands' => 'Mars',
            'quantity' => '48 g',
            'nutrition_data_per' => '100g',
            'nutriments' => ['energy-kcal_100g' => 497, 'proteins_100g' => 9.4],
        ]);

        $component = Volt::actingAs($this->user)->test('scan')
            ->set('photo', $this->photo())
            ->set('detectedBarcode', '5000159407236')
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 'confirm')
            ->assertSee('Is this right?')
            ->assertSee('Snickers')
            ->call('yesAddIt')
            ->assertSet('step', 'quantity')
            ->set('quantity', 2)
            ->set('unit', QuantityUnit::Unit->value)
            ->call('addToPantry')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        // A canonical product was imported from OFF, keyless.
        $this->assertDatabaseHas('canonical_products', ['gtin' => '5000159407236', 'name' => 'Snickers']);

        // A pantry item + purchase ledger row now exist.
        $item = PantryItem::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame(2.0, (float) $item->current_quantity);
        $this->assertSame(1, $item->transactions()->where('type', PantryTransactionType::Purchase)->count());

        // The audit row records the barcode match and the stored image.
        $job = ProductResolutionJob::latest('id')->first();
        $this->assertSame('matched_barcode', $job->status);
        $this->assertNotNull($job->uploaded_image_path);
        Storage::disk('public')->assertExists($job->uploaded_image_path);
    }

    public function test_barcode_path_works_without_an_uploaded_photo(): void
    {
        // The browser file upload can be unavailable (e.g. blocked temp-upload
        // endpoint); a barcode read on-device must still resolve with no image.
        $this->fakeOff([
            'code' => '5000159407236',
            'product_name' => 'Snickers',
            'brands' => 'Mars',
            'nutriments' => ['energy-kcal_100g' => 497, 'proteins_100g' => 9.4],
        ]);

        Volt::actingAs($this->user)->test('scan')
            ->set('detectedBarcode', '5000159407236')
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 'confirm')
            ->assertSee('Snickers');

        $job = ProductResolutionJob::latest('id')->first();
        $this->assertSame('matched_barcode', $job->status);
        $this->assertNull($job->uploaded_image_path);
    }

    public function test_barcode_path_survives_a_bad_out_of_range_nutriment(): void
    {
        // Real OFF data contains impossible crowd-sourced values that overflow the
        // decimal(8,2) nutrient columns on Postgres (a 500 → blank page). The scan
        // must still resolve, dropping only the bad value.
        $this->fakeOff([
            'code' => '5000159407236',
            'product_name' => 'Snickers',
            'brands' => 'Mars',
            'nutrition_data_per' => '100g',
            'nutriments' => ['energy-kcal_100g' => 123456789, 'proteins_100g' => 9.4],
        ]);

        Volt::actingAs($this->user)->test('scan')
            ->set('detectedBarcode', '5000159407236')
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 'confirm')
            ->assertSee('Snickers');

        $version = ProductVersion::latest('id')->first();
        $this->assertNull($version->calories);       // impossible value dropped
        $this->assertSame('9.40', (string) $version->protein); // valid value kept
    }

    // --- 2. Photo path with a faked identifier -----------------------------

    public function test_photo_path_identifies_confirms_and_adds_to_pantry(): void
    {
        Http::preventStrayRequests(); // no barcode → no OFF call expected

        $product = CanonicalProduct::factory()->create([
            'gtin' => null,
            'brand' => 'The Gym Kitchen',
            'name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayo',
            'pack_size_value' => 189,
            'pack_size_unit' => 'g',
        ]);

        $this->fakeIdentification([
            'brand' => 'The Gym Kitchen',
            'product_name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayo',
            'pack_size' => '189g',
            'barcode' => null,
            'confidence' => 0.96,
        ]);

        Volt::actingAs($this->user)->test('scan')
            ->set('photo', $this->photo())
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 'confirm')
            ->assertSet('matchedProductId', $product->id)
            ->call('yesAddIt')
            ->set('quantity', 1)
            ->set('unit', QuantityUnit::Unit->value)
            ->call('addToPantry')
            ->assertSet('step', 'done');

        $item = PantryItem::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame($product->id, $item->canonical_product_id);
        $this->assertSame(1.0, (float) $item->current_quantity);

        $job = ProductResolutionJob::latest('id')->first();
        $this->assertSame('matched_exact', $job->status);
        $this->assertSame('openrouter', $job->model_provider);
    }

    // --- 3. "Wrong product" correction is recorded -------------------------

    public function test_wrong_product_records_the_correction_as_evidence(): void
    {
        $this->fakeOff([
            'code' => '5000159407236',
            'product_name' => 'Snickers',
            'brands' => 'Mars',
            'nutriments' => ['energy-kcal_100g' => 497],
        ]);

        $component = Volt::actingAs($this->user)->test('scan')
            ->set('photo', $this->photo())
            ->set('detectedBarcode', '5000159407236')
            ->call('analyze')
            ->assertSet('step', 'confirm')
            ->call('wrongProduct')
            ->assertSet('step', 'corrected')
            ->assertSee('correction');

        $product = CanonicalProduct::where('gtin', '5000159407236')->firstOrFail();
        $job = ProductResolutionJob::latest('id')->first();

        $this->assertNotNull($job->corrected_at);
        $this->assertSame($product->id, $job->user_correction['rejected_product_id']);
        $this->assertFalse($job->user_correction['was_suggestion']);
    }

    // --- 4. Unknown result → manual fallback -------------------------------

    public function test_unknown_result_shows_the_manual_fallback_state(): void
    {
        // Barcode present but OFF has no match, and the catalogue is empty →
        // the resolver classifies it needs_research (no crash).
        Http::fake(['world.openfoodfacts.org/*' => Http::response(['status' => 0], 200)]);

        Volt::actingAs($this->user)->test('scan')
            ->set('photo', $this->photo())
            ->set('detectedBarcode', '9999999999999')
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 'unknown')
            ->assertSee('confidently identify')
            ->assertSee('Add manually');

        $this->assertDatabaseHas('product_resolution_jobs', ['status' => 'needs_research']);
    }

    // --- 5. Key-absent photo path degrades gracefully ----------------------

    public function test_photo_path_without_ai_key_shows_a_friendly_message(): void
    {
        // Simulate an identifier that fails because no OPENROUTER_API_KEY is set.
        $this->app->bind(ProductIdentifier::class, fn () => new class implements ProductIdentifier
        {
            public function identify(ProductImage $image): IdentifiedProduct
            {
                throw new RuntimeException('OpenRouter API key is not configured.');
            }
        });

        Volt::actingAs($this->user)->test('scan')
            ->set('photo', $this->photo())
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 'ai_unavailable')
            ->assertSee('needs AI configuration');

        // No pantry item was created and the app did not error.
        $this->assertDatabaseCount('pantry_items', 0);
    }

    public function test_photo_requires_an_image_before_analyzing(): void
    {
        Volt::actingAs($this->user)->test('scan')
            ->call('analyze')
            ->assertHasErrors(['photo']);
    }
}
