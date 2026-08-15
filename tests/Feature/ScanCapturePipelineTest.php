<?php

namespace Tests\Feature;

use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\Enums\QuantityUnit;
use App\Enums\ScanCaptureStatus;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Models\PantryItem;
use App\Models\ProductVersion;
use App\Models\ScanCapture;
use App\Models\User;
use App\Services\ScanCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The pipelined scanner's engine: queue -> background identify/resolve -> the
 * provenance gate. On the test (sync) queue driver the job runs inline, so
 * queue() settles the capture before it returns — which is exactly the
 * degraded-fallback behavior documented in DEPLOY.md Part B2.
 */
class ScanCapturePipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('prism.providers.openrouter.api_key', '');
        $this->user = User::factory()->onboarded()->create();
    }

    private function service(): ScanCaptureService
    {
        return app(ScanCaptureService::class);
    }

    private function localProduct(string $gtin = '2885053319044'): CanonicalProduct
    {
        $product = CanonicalProduct::factory()->create([
            'brand' => 'Mornflake',
            'name' => 'Porridge Oats',
            'gtin' => $gtin,
        ]);
        ProductVersion::factory()->for($product)->create([
            'serving_basis' => ServingBasis::Per100g,
            'calories' => 370,
            'protein' => 11.0,
        ]);

        return $product;
    }

    private function identifierReturning(array $fields): void
    {
        $this->app->bind(ProductIdentifier::class, fn () => new class($fields) implements ProductIdentifier
        {
            public function __construct(private readonly array $fields) {}

            public function identify(ProductImage $image): IdentifiedProduct
            {
                return IdentifiedProduct::fromArray($this->fields);
            }
        });
    }

    // --- Identity-grade provenance auto-applies, undoably -------------------

    public function test_local_barcode_capture_auto_adds_one_unit(): void
    {
        $product = $this->localProduct();

        $capture = $this->service()->queue($this->user, null, $product->gtin);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::AutoAdded, $capture->status);
        $this->assertSame('matched_barcode', $capture->provenance);
        $this->assertSame($product->id, $capture->matched_product_id);
        $this->assertNull($capture->consumption_event_id);

        $item = PantryItem::findOrFail($capture->pantry_item_id);
        $this->assertSame(1.0, (float) $item->current_quantity);
        $this->assertSame(QuantityUnit::Unit, $item->quantity_unit);
    }

    public function test_eat_now_capture_also_logs_todays_intake(): void
    {
        $this->localProduct();

        $capture = $this->service()->queue($this->user, null, '2885053319044', eatNow: true);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::AutoAdded, $capture->status);
        $this->assertNotNull($capture->consumption_event_id);
        // Stocked 1, ate 1 — the shelf is level and today has the record.
        $this->assertSame(0.0, (float) $capture->pantryItem->fresh()->current_quantity);
        $this->assertSame(1, $this->user->consumptionEvents()->count());
    }

    public function test_undo_reverses_the_add_and_the_meal(): void
    {
        $this->localProduct();

        $capture = $this->service()->queue($this->user, null, '2885053319044', eatNow: true);
        $capture->refresh();

        $this->service()->undo($capture);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Undone, $capture->status);
        $this->assertSame(0, $this->user->consumptionEvents()->count());
        // Meal deletion restored the unit; the undo then removed the stocked unit.
        $this->assertSame(0.0, (float) PantryItem::findOrFail($capture->pantry_item_id)->current_quantity);
    }

    public function test_fuzzy_match_at_or_above_threshold_auto_adds(): void
    {
        $this->localProduct();
        // Same brand/name wording, tiny drift -> similarity >= 0.85, no barcode.
        $this->identifierReturning([
            'brand' => 'Mornflake', 'product_name' => 'Porridge Oat', 'confidence' => 0.9,
        ]);

        $capture = $this->service()->queue($this->user, 'scans/fake.jpg', null);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::AutoAdded, $capture->status);
        $this->assertSame('matched_fuzzy', $capture->provenance);
        $this->assertGreaterThanOrEqual(0.85, (float) $capture->confidence);
    }

    // --- The suggestion band never applies without the user -----------------

    public function test_suggestion_band_waits_for_confirmation(): void
    {
        $this->localProduct();
        $this->identifierReturning([
            'brand' => 'Mornflake', 'product_name' => 'Oaty Porridge', 'confidence' => 0.7,
        ]);

        $capture = $this->service()->queue($this->user, 'scans/fake.jpg', null);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Suggested, $capture->status);
        $this->assertDatabaseCount('pantry_items', 0);

        $this->service()->confirm($capture);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Added, $capture->status);
        $this->assertSame(1.0, (float) $capture->pantryItem->current_quantity);
    }

    public function test_a_mistaken_capture_can_be_dismissed_but_applied_ones_cannot(): void
    {
        $this->localProduct();

        // An in-flight (or settled, unapplied) capture dismisses cleanly…
        $stale = ScanCapture::create(['user_id' => $this->user->id, 'barcode' => '999', 'status' => ScanCaptureStatus::Identifying]);
        $this->service()->discard($stale);
        $this->assertSame(ScanCaptureStatus::Dismissed, $stale->fresh()->status);

        // …and a dismissed capture is dead to the pipeline: a late job no-ops.
        $this->service()->process($stale->fresh(), app(ProductIdentifier::class));
        $this->assertSame(ScanCaptureStatus::Dismissed, $stale->fresh()->status);
        $this->assertDatabaseCount('pantry_items', 0);

        // An applied capture refuses dismissal — Undo is the only way out,
        // so a dismiss can never strand pantry/intake writes.
        $applied = $this->service()->queue($this->user, null, '2885053319044');
        $this->service()->discard($applied->refresh());
        $this->assertSame(ScanCaptureStatus::AutoAdded, $applied->fresh()->status);
    }

    public function test_rejecting_a_suggestion_records_the_correction(): void
    {
        $this->localProduct();
        $this->identifierReturning([
            'brand' => 'Mornflake', 'product_name' => 'Oaty Porridge', 'confidence' => 0.7,
        ]);

        $capture = $this->service()->queue($this->user, 'scans/fake.jpg', null);
        $capture->refresh();

        $this->service()->reject($capture);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Rejected, $capture->status);
        $this->assertDatabaseCount('pantry_items', 0);
        $this->assertNotNull($capture->resolutionJob->corrected_at);
        $this->assertSame($capture->matched_product_id, $capture->resolutionJob->user_correction['rejected_product_id']);
    }

    // --- Honest settled states ----------------------------------------------

    public function test_unresolvable_capture_settles_as_unknown(): void
    {
        Http::fake(['world.openfoodfacts.org/*' => Http::response(['status' => 0], 200)]);

        $capture = $this->service()->queue($this->user, null, '00000000');
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Unknown, $capture->status);
        $this->assertDatabaseCount('pantry_items', 0);
    }

    public function test_photo_capture_without_ai_settles_honestly(): void
    {
        $this->app->bind(ProductIdentifier::class, fn () => new class implements ProductIdentifier
        {
            public function identify(ProductImage $image): IdentifiedProduct
            {
                throw new RuntimeException('OpenRouter API key is not configured.');
            }
        });

        $capture = $this->service()->queue($this->user, 'scans/fake.jpg', null);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::AiUnavailable, $capture->status);
    }

    public function test_processing_a_settled_capture_is_a_no_op(): void
    {
        $product = $this->localProduct();

        $capture = $this->service()->queue($this->user, null, $product->gtin);
        $capture->refresh();

        // A retried/duplicate job must never double-apply.
        $this->service()->process($capture, app(ProductIdentifier::class));

        $this->assertSame(1.0, (float) PantryItem::findOrFail($capture->fresh()->pantry_item_id)->current_quantity);
        $this->assertSame(1, ScanCapture::count());
    }

    // --- Self-healing: the poll rescues captures the queue abandoned --------

    public function test_a_stranded_capture_is_rescued_and_settles(): void
    {
        // A capture the queue never processed (worker down / job lost):
        // created directly, in-flight, last touched beyond the staleness bar.
        $product = $this->localProduct();
        $capture = ScanCapture::create([
            'user_id' => $this->user->id,
            'barcode' => $product->gtin,
            'status' => ScanCaptureStatus::Queued,
        ]);
        ScanCapture::whereKey($capture->id)->update([
            'updated_at' => now()->subSeconds(ScanCaptureService::STALE_AFTER_SECONDS + 10),
        ]);

        $this->service()->rescueStale($this->user, app(ProductIdentifier::class));

        $capture->refresh();
        $this->assertSame(ScanCaptureStatus::AutoAdded, $capture->status);
        $this->assertSame(1.0, (float) PantryItem::findOrFail($capture->pantry_item_id)->current_quantity);
    }

    public function test_rescue_leaves_fresh_in_flight_captures_to_the_worker(): void
    {
        // Still inside the staleness window — a live worker owns it.
        $capture = ScanCapture::create([
            'user_id' => $this->user->id,
            'barcode' => '2885053319044',
            'status' => ScanCaptureStatus::Queued,
        ]);

        $this->service()->rescueStale($this->user, app(ProductIdentifier::class));

        $this->assertSame(ScanCaptureStatus::Queued, $capture->fresh()->status);
    }

    public function test_rescue_never_touches_another_users_captures(): void
    {
        $other = User::factory()->onboarded()->create();
        $capture = ScanCapture::create([
            'user_id' => $other->id,
            'barcode' => '2885053319044',
            'status' => ScanCaptureStatus::Queued,
        ]);
        ScanCapture::whereKey($capture->id)->update([
            'updated_at' => now()->subSeconds(ScanCaptureService::STALE_AFTER_SECONDS + 10),
        ]);

        $this->service()->rescueStale($this->user, app(ProductIdentifier::class));

        $this->assertSame(ScanCaptureStatus::Queued, $capture->fresh()->status);
    }
}
