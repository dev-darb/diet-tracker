<?php

namespace Tests\Feature;

use App\Enums\ScanCaptureStatus;
use App\Models\CanonicalProduct;
use App\Models\ScanCapture;
use App\Models\User;
use App\Services\ScanCaptureService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The scanner surface: the capture drop-box endpoint and the results-stack
 * component. The pipeline itself (provenance gate, auto-apply, undo mechanics)
 * is covered service-level in ScanCapturePipelineTest — here we test what the
 * screen and the endpoint do with it.
 */
class ScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('prism.providers.openrouter.api_key', '');
        Storage::fake(config('foody.scans.disk'));
        $this->user = User::factory()->onboarded()->create();
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

    // --- The capture drop-box -----------------------------------------------

    public function test_a_barcode_post_queues_settles_and_auto_adds(): void
    {
        $this->fakeOff([
            'code' => '5000159407236',
            'product_name' => 'Snickers',
            'brands' => 'Mars',
            'quantity' => '48 g',
            'nutrition_data_per' => '100g',
            'nutriments' => ['energy-kcal_100g' => 497, 'proteins_100g' => 9.4],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('scan.captures.store'), ['barcode' => '5000159407236']);

        $response->assertCreated();

        // Sync driver = the job already ran: the capture settled while queued.
        $capture = ScanCapture::findOrFail($response->json('id'));
        $this->assertSame(ScanCaptureStatus::AutoAdded, $capture->status);
        $this->assertSame('matched_barcode', $capture->provenance);
        $this->assertSame(1.0, (float) $capture->pantryItem->current_quantity);
        $this->assertSame('Snickers', $capture->matchedProduct->name);
    }

    public function test_a_photo_post_stores_the_image_and_survives_no_ai_key(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('scan.captures.store'), [
            'photo' => UploadedFile::fake()->image('pack.jpg', 800, 600),
        ]);

        $response->assertCreated();

        $capture = ScanCapture::findOrFail($response->json('id'));
        $this->assertNotNull($capture->image_path);
        Storage::disk(config('foody.scans.disk'))->assertExists($capture->image_path);
    }

    /**
     * Captures land on whichever disk is configured, so moving them to object
     * storage — which is what a capture needs before it can become part of a
     * food's identity rather than a throwaway — is an env change, not a rebuild.
     */
    public function test_captures_land_on_the_configured_disk(): void
    {
        config(['foody.scans.disk' => 'captures-test']);
        Storage::fake('captures-test');

        $response = $this->actingAs($this->user)->postJson(route('scan.captures.store'), [
            'photo' => UploadedFile::fake()->image('pack.jpg', 800, 600),
        ]);

        $response->assertCreated();

        $capture = ScanCapture::findOrFail($response->json('id'));
        Storage::disk('captures-test')->assertExists($capture->image_path);
        // Keyless photo path settles honestly instead of 500ing.
        $this->assertSame(ScanCaptureStatus::AiUnavailable, $capture->status);
    }

    public function test_an_empty_post_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('scan.captures.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_guests_cannot_post_captures(): void
    {
        $this->postJson(route('scan.captures.store'), ['barcode' => '5000159407236'])
            ->assertUnauthorized();
    }

    // --- The results stack --------------------------------------------------

    public function test_the_stack_renders_every_settled_state(): void
    {
        $product = CanonicalProduct::factory()->create(['brand' => 'Mars', 'name' => 'Snickers']);

        ScanCapture::create(['user_id' => $this->user->id, 'barcode' => '111', 'status' => ScanCaptureStatus::Identifying]);
        ScanCapture::create(['user_id' => $this->user->id, 'barcode' => '222', 'status' => ScanCaptureStatus::AutoAdded, 'provenance' => 'matched_barcode', 'matched_product_id' => $product->id]);
        ScanCapture::create(['user_id' => $this->user->id, 'status' => ScanCaptureStatus::Suggested, 'provenance' => 'suggestion', 'confidence' => 0.7, 'matched_product_id' => $product->id]);
        ScanCapture::create(['user_id' => $this->user->id, 'status' => ScanCaptureStatus::Unknown]);
        ScanCapture::create(['user_id' => $this->user->id, 'status' => ScanCaptureStatus::AiUnavailable]);
        ScanCapture::create(['user_id' => $this->user->id, 'status' => ScanCaptureStatus::Failed, 'error' => 'boom']);

        Volt::actingAs($this->user)->test('scan')
            // In-flight shows the work ticker (any line) with the barcode.
            ->assertSee('· 111')
            ->assertSee('IN PANTRY')
            ->assertSee('is this right?')
            ->assertSee('IDENTIFY THIS YET')
            ->assertSee('OFF FOR NOW')
            ->assertSee('IDENTIFICATION FAILED')
            // The first stored product of a session earns the shopping offer.
            ->assertSee('Adding groceries?');
    }

    public function test_confirming_a_suggestion_applies_it_from_the_card(): void
    {
        $product = CanonicalProduct::factory()->create();
        $capture = ScanCapture::create([
            'user_id' => $this->user->id,
            'status' => ScanCaptureStatus::Suggested,
            'provenance' => 'suggestion',
            'confidence' => 0.7,
            'matched_product_id' => $product->id,
        ]);

        Volt::actingAs($this->user)->test('scan')
            ->call('confirmCapture', $capture->id)
            ->assertHasNoErrors();

        $this->assertSame(ScanCaptureStatus::Added, $capture->fresh()->status);
        $this->assertSame(1.0, (float) $capture->fresh()->pantryItem->current_quantity);
    }

    public function test_eat_now_then_undo_from_the_card(): void
    {
        $this->fakeOff([
            'code' => '5000159407236',
            'product_name' => 'Snickers',
            'brands' => 'Mars',
            'nutrition_data_per' => '100g',
            'nutriments' => ['energy-kcal_100g' => 497],
        ]);

        $capture = app(ScanCaptureService::class)->queue($this->user, null, '5000159407236');

        $component = Volt::actingAs($this->user)->test('scan')
            ->call('eatNowCapture', $capture->id);

        $capture->refresh();
        $this->assertNotNull($capture->consumption_event_id);
        $this->assertSame(1, $this->user->consumptionEvents()->count());

        $component->call('undoCapture', $capture->id);

        $capture->refresh();
        $this->assertSame(ScanCaptureStatus::Undone, $capture->status);
        $this->assertSame(0, $this->user->consumptionEvents()->count());
    }

    public function test_card_actions_are_scoped_to_the_owner(): void
    {
        $product = CanonicalProduct::factory()->create();
        $capture = ScanCapture::create([
            'user_id' => $this->user->id,
            'status' => ScanCaptureStatus::Suggested,
            'provenance' => 'suggestion',
            'confidence' => 0.7,
            'matched_product_id' => $product->id,
        ]);

        $other = User::factory()->onboarded()->create();

        $this->expectException(ModelNotFoundException::class);

        Volt::actingAs($other)->test('scan')->call('confirmCapture', $capture->id);

        $this->assertSame(ScanCaptureStatus::Suggested, $capture->fresh()->status);
    }
}
