<?php

namespace App\Services;

use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\Enums\QuantityUnit;
use App\Enums\ScanCaptureStatus;
use App\Jobs\ProcessScanCapture;
use App\Models\ScanCapture;
use App\Models\User;
use Throwable;

/**
 * The pipelined scanner's engine ("the interface should never make the user
 * wait for the AI", founder, Aug 2026). A capture is queued the instant the
 * shutter fires; this service identifies + resolves it off the request cycle
 * and applies the PROVENANCE GATE:
 *
 *   barcode / exact (1.0) or fuzzy ≥ 0.85  → auto-apply, qty 1, undoable —
 *                                            these are the bands ProductResolver
 *                                            itself already treats as identity.
 *   suggestion band (0.60–0.85)            → ask the user (never auto-applied).
 *   below the floor / no AI / error        → honest settled state, photo kept.
 *
 * Speed must never silently introduce bad food data: nothing below the
 * resolver's own auto-match threshold ever writes to the pantry or the intake
 * ledger without an explicit confirm, and every automatic write is reversible
 * through the ledger's compensating corrections.
 */
class ScanCaptureService
{
    public function __construct(
        private readonly ProductResolver $resolver,
        private readonly PantryService $pantry,
        private readonly ConsumptionService $consumption,
    ) {}

    /** Create the capture row and hand it to the queue. Instant by design. */
    public function queue(User $user, ?string $imagePath, ?string $barcode, bool $eatNow = false): ScanCapture
    {
        $capture = ScanCapture::create([
            'user_id' => $user->id,
            'image_path' => $imagePath,
            'barcode' => $barcode !== null && trim($barcode) !== '' ? trim($barcode) : null,
            'eat_now' => $eatNow,
            'status' => ScanCaptureStatus::Queued,
        ]);

        ProcessScanCapture::dispatch($capture->id);

        return $capture;
    }

    /** The background pipeline: identify → resolve → gate. Called by the job. */
    public function process(ScanCapture $capture, ProductIdentifier $identifier): void
    {
        if (! $capture->status->inFlight()) {
            return; // idempotent: a retried job never re-applies a settled capture
        }

        $capture->update(['status' => ScanCaptureStatus::Identifying]);

        $meta = [];

        if ($capture->barcode !== null) {
            // Keyless deterministic fast path — no AI cost, no image needed.
            $detected = IdentifiedProduct::fromArray(['barcode' => $capture->barcode, 'confidence' => 1.0]);
        } elseif ($capture->image_path !== null) {
            try {
                $detected = $identifier->identify(ProductImage::fromStoragePath($capture->image_path, 'public'));
            } catch (Throwable $e) {
                // KEY-ABSENT GRACE: no provider key → honest notice, never a 500.
                report($e);
                $capture->update(['status' => ScanCaptureStatus::AiUnavailable]);

                return;
            }

            $config = config('ai.product_identifier');
            $meta = ['model_provider' => $config['provider'] ?? null, 'model_name' => $config['model'] ?? null];
        } else {
            $capture->update(['status' => ScanCaptureStatus::Failed, 'error' => 'Capture carried neither a photo nor a barcode.']);

            return;
        }

        try {
            $result = $this->resolver->resolve($detected, $capture->user, $meta);
            $result->resolutionJob->update(['uploaded_image_path' => $capture->image_path]);
        } catch (Throwable $e) {
            report($e);
            $capture->update(['status' => ScanCaptureStatus::Failed, 'error' => $e->getMessage()]);

            return;
        }

        $capture->fill([
            'resolution_job_id' => $result->resolutionJob->id,
            'matched_product_id' => $result->canonicalProduct?->id,
            'provenance' => $result->status->value,
            'confidence' => $result->confidence,
        ]);

        if ($result->canonicalProduct === null) {
            $capture->fill(['status' => ScanCaptureStatus::Unknown])->save();

            return;
        }

        if ($result->isSuggestion()) {
            // The 0.60–0.85 band: the confirm screen is genuinely protective here.
            $capture->fill(['status' => ScanCaptureStatus::Suggested])->save();

            return;
        }

        // Identity-grade provenance — apply automatically, undoably.
        $capture->save();
        $this->apply($capture, ScanCaptureStatus::AutoAdded);
    }

    /** User confirmed a suggested match — apply it exactly like an auto-add. */
    public function confirm(ScanCapture $capture): void
    {
        if ($capture->status === ScanCaptureStatus::Suggested && $capture->matched_product_id !== null) {
            $this->apply($capture, ScanCaptureStatus::Added);
        }
    }

    /**
     * User rejected a suggested match — recorded as evidence on the resolution
     * job (corrections are product intelligence, brief §11). Photo retained.
     */
    public function reject(ScanCapture $capture): void
    {
        if ($capture->status !== ScanCaptureStatus::Suggested) {
            return;
        }

        $capture->resolutionJob?->update([
            'user_correction' => [
                'rejected_product_id' => $capture->matched_product_id,
                'was_suggestion' => true,
                'detected_fields' => $capture->resolutionJob->detected_fields,
            ],
            'corrected_at' => now(),
        ]);

        $capture->update(['status' => ScanCaptureStatus::Rejected]);
    }

    /**
     * Reverse an applied capture: the consumption event is deleted (its ledger
     * correction restores stock), then the stocked unit is removed.
     */
    public function undo(ScanCapture $capture): void
    {
        if (! $capture->status->applied()) {
            return;
        }

        if ($capture->consumptionEvent !== null) {
            $this->consumption->deleteConsumption($capture->consumptionEvent);
        }

        $item = $capture->pantryItem;

        if ($item !== null && (float) $item->current_quantity > 0) {
            $this->pantry->manualRemove($item, min(1.0, (float) $item->current_quantity));
        }

        $capture->update([
            'status' => ScanCaptureStatus::Undone,
            'consumption_event_id' => null,
        ]);
    }

    private function apply(ScanCapture $capture, ScanCaptureStatus $as): void
    {
        $item = $this->pantry->purchase(
            $capture->user,
            $capture->matchedProduct,
            1.0,
            QuantityUnit::Unit,
            ['scan_capture_id' => $capture->id],
        );

        $eventId = null;

        if ($capture->eat_now) {
            $eventId = $this->consumption->consumePantryItem($capture->user, $item, 1.0)->id;
        }

        $capture->update([
            'status' => $as,
            'pantry_item_id' => $item->id,
            'consumption_event_id' => $eventId,
        ]);
    }
}
