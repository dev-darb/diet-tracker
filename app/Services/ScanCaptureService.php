<?php

namespace App\Services;

use App\AI\Contracts\MealPhotoInterpreter;
use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\Enums\CaptureKind;
use App\Enums\QuantityUnit;
use App\Enums\ScanCaptureStatus;
use App\Jobs\ProcessScanCapture;
use App\Models\PantryItem;
use App\Models\ScanCapture;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    /**
     * A capture still in flight after this long has been abandoned by the
     * queue (driver pointed at a worker that isn't running, worker died,
     * job lost). The scan page's poll then processes it inline — the worker
     * is an optimisation, never a dependency.
     */
    public const STALE_AFTER_SECONDS = 20;

    /** Inline rescues per poll tick — keeps the request bounded. */
    private const RESCUES_PER_PULSE = 2;

    /**
     * Below this triage confidence the classification is a guess — the card
     * asks "What am I looking at?" instead (infer when reasonably confident;
     * ask when guessing would create worse UX or bad nutrition data).
     */
    public const KIND_CONFIDENCE_FLOOR = 0.5;

    public function __construct(
        private readonly ProductResolver $resolver,
        private readonly PantryService $pantry,
        private readonly ConsumptionService $consumption,
        private readonly MealPhotoInterpreter $mealInterpreter,
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
            'stage' => 'looking',
        ]);

        ProcessScanCapture::dispatch($capture->id);

        return $capture;
    }

    /**
     * SELF-HEALING: process any of the user's captures the queue has left
     * stranded. Called from the scan page's poll, so a capture always settles
     * while the user is watching — with a healthy worker this finds nothing
     * (captures settle in seconds and never go stale). Idempotence and the
     * worker race are handled by process()'s inFlight() guard and apply()'s
     * row lock.
     */
    public function rescueStale(User $user, ProductIdentifier $identifier): void
    {
        $stranded = ScanCapture::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ScanCaptureStatus::Queued, ScanCaptureStatus::Identifying])
            ->where('updated_at', '<=', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->oldest()
            ->limit(self::RESCUES_PER_PULSE)
            ->get();

        if ($stranded->isNotEmpty()) {
            // Rescue is the SAFETY NET, not the intended path — a healthy
            // worker settles captures long before staleness. Seeing this in
            // the logs means the queue worker is missing or down (DEPLOY B2).
            Log::warning('Scan captures rescued inline — is the queue worker running?', [
                'count' => $stranded->count(),
                'queue_connection' => config('queue.default'),
            ]);
        }

        foreach ($stranded as $capture) {
            $this->process($capture, $identifier);
        }
    }

    /**
     * The background pipeline: identify (with triage) → route by kind →
     * resolve → gate. Called by the job, or inline by the rescue path. The
     * `stage` column tracks REAL progress so the UI's feedback maps to what
     * the backend is actually doing, never a fictional ticker.
     */
    public function process(ScanCapture $capture, ProductIdentifier $identifier): void
    {
        if (! $capture->status->inFlight()) {
            return; // idempotent: a retried job never re-applies a settled capture
        }

        $capture->update(['status' => ScanCaptureStatus::Identifying, 'stage' => 'identifying']);

        $meta = [];
        $forced = $capture->kind; // set when the user answered "What am I looking at?"

        if ($capture->barcode !== null) {
            // Keyless deterministic fast path — no AI cost, no image needed.
            $detected = IdentifiedProduct::fromArray(['barcode' => $capture->barcode, 'confidence' => 1.0]);
            $capture->fill(['kind' => CaptureKind::PackagedProduct]);
        } elseif ($capture->image_path !== null) {
            if ($forced === CaptureKind::PreparedMeal) {
                $this->settleAsMeal($capture, null);

                return;
            }

            try {
                $detected = $identifier->identify(
                    ProductImage::fromStoragePath($capture->image_path, config('foody.scans.disk')),
                    $forced?->hintLabel(),
                );
            } catch (Throwable $e) {
                // KEY-ABSENT GRACE: no provider key → honest notice, never a 500.
                report($e);
                $capture->update(['status' => ScanCaptureStatus::AiUnavailable, 'stage' => null]);

                return;
            }

            // Triage: the user's asserted kind wins; otherwise the model's,
            // but only when it is confident enough to act on.
            $kind = $forced ?? CaptureKind::fromModel($detected->kind);

            if ($forced === null) {
                if ($kind === CaptureKind::PreparedMeal && $detected->confidence >= self::KIND_CONFIDENCE_FLOOR) {
                    $this->settleAsMeal($capture, $detected->dishName);

                    return;
                }

                if ($kind === CaptureKind::Unknown || $detected->confidence < self::KIND_CONFIDENCE_FLOOR) {
                    // Genuinely uncertain — asking beats guessing (and beats
                    // forcing a banana into "packaged product").
                    $capture->update(['kind' => $kind, 'status' => ScanCaptureStatus::NeedsKind, 'stage' => null]);

                    return;
                }
            }

            $capture->fill(['kind' => $kind]);

            $config = config('ai.product_identifier');
            $meta = ['model_provider' => $config['provider'] ?? null, 'model_name' => $config['model'] ?? null];
        } else {
            $capture->update(['status' => ScanCaptureStatus::Failed, 'error' => 'Capture carried neither a photo nor a barcode.', 'stage' => null]);

            return;
        }

        $capture->fill(['stage' => 'checking'])->save();

        try {
            $result = $this->resolver->resolve($detected, $capture->user, $meta);
            $result->resolutionJob->update(['uploaded_image_path' => $capture->image_path]);
        } catch (Throwable $e) {
            report($e);
            $capture->update(['status' => ScanCaptureStatus::Failed, 'error' => $e->getMessage(), 'stage' => null]);

            return;
        }

        $capture->fill([
            'resolution_job_id' => $result->resolutionJob->id,
            'matched_product_id' => $result->canonicalProduct?->id,
            'provenance' => $result->status->value,
            'confidence' => $result->confidence,
        ]);

        if ($result->canonicalProduct === null) {
            $capture->fill(['status' => ScanCaptureStatus::Unknown, 'stage' => null])->save();

            return;
        }

        if ($result->isSuggestion()) {
            // The 0.60–0.85 band: the confirm screen is genuinely protective here.
            $capture->fill(['status' => ScanCaptureStatus::Suggested, 'stage' => null])->save();

            return;
        }

        // Identity-grade provenance — apply automatically, undoably.
        $capture->fill(['stage' => 'finishing'])->save();
        $this->apply($capture, ScanCaptureStatus::AutoAdded);
    }

    /**
     * A prepared meal never touches the pantry gate: the specialised meal
     * interpreter reads the plate NOW — while the photo is still on this
     * instance's disk (serverless local storage makes later reads unreliable)
     * — and the reading is persisted so the meal flow opens prefilled without
     * ever needing the image again.
     */
    private function settleAsMeal(ScanCapture $capture, ?string $dishName): void
    {
        $reading = null;

        if ($capture->image_path !== null && $this->mealInterpreter->available()) {
            try {
                $reading = $this->mealInterpreter->interpret(
                    ProductImage::fromStoragePath($capture->image_path, config('foody.scans.disk')),
                    $this->pantryCandidates($capture->user),
                );
            } catch (Throwable $e) {
                report($e); // the capture still settles as a meal with what triage saw
            }
        }

        $capture->update([
            'kind' => CaptureKind::PreparedMeal,
            'dish_name' => $reading?->dishName ?? $dishName,
            'meal_reading' => $reading !== null ? [
                'dish_name' => $reading->dishName,
                'pantry_item_ids' => $reading->pantryItemIds,
                'also_seen' => $reading->alsoSeen,
                'confidence' => $reading->confidence,
            ] : null,
            'status' => ScanCaptureStatus::Meal,
            'stage' => null,
        ]);
    }

    /**
     * The user answered "What am I looking at?" on an uncertain capture: force
     * the kind and requeue through the same pipeline. A meal goes straight to
     * interpretation; product kinds re-identify with the user's claim as a hint.
     */
    public function setKind(ScanCapture $capture, CaptureKind $kind): void
    {
        // Valid from the uncertain state AND from an un-logged meal card
        // ("Not a meal?") — but never once anything has been written.
        $reclassifiable = $capture->status === ScanCaptureStatus::NeedsKind
            || ($capture->status === ScanCaptureStatus::Meal && $capture->consumption_event_id === null);

        if (! $reclassifiable) {
            return;
        }

        $capture->update([
            'kind' => $kind,
            'status' => ScanCaptureStatus::Queued,
            'stage' => 'looking',
            'dish_name' => null,
            'meal_reading' => null,
        ]);

        ProcessScanCapture::dispatch($capture->id);
    }

    /**
     * The user's in-stock items as interpreter grounding candidates — the same
     * recognise-and-select list the meal flow offers.
     *
     * @return list<array{id: int, label: string}>
     */
    private function pantryCandidates(User $user): array
    {
        return PantryItem::query()
            ->with('canonicalProduct')
            ->where('user_id', $user->id)
            ->where('current_quantity', '>', 0)
            ->get()
            ->map(fn (PantryItem $item) => [
                'id' => $item->id,
                'label' => trim(($item->canonicalProduct->brand ?? '').' '.$item->canonicalProduct->name),
            ])
            ->values()
            ->all();
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
     * Remove a capture from the stack — a mis-fired shutter, a duplicate, or
     * settled noise. Never valid on an applied capture (undo that first: a
     * dismiss must not silently strand pantry/intake writes). Dismissing an
     * in-flight capture is safe: the job's inFlight() guard skips it.
     */
    public function discard(ScanCapture $capture): void
    {
        if ($capture->status->applied()) {
            return;
        }

        $capture->update(['status' => ScanCaptureStatus::Dismissed]);
    }

    /**
     * "…and I'm eating it now", tapped on an applied result card: log one unit
     * of the just-stocked item to today. One tap, undoable via undo().
     */
    public function eatNow(ScanCapture $capture): void
    {
        if (! $capture->status->applied() || $capture->consumption_event_id !== null || $capture->pantryItem === null) {
            return;
        }

        $event = $this->consumption->consumePantryItem($capture->user, $capture->pantryItem, 1.0);

        $capture->update(['eat_now' => true, 'consumption_event_id' => $event->id]);
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
        // Serialised under a row lock: a queue worker that finally wakes up
        // and the poll's inline rescue can race to apply the same capture —
        // whichever arrives second must see the applied (or dismissed) state
        // and walk away, or the user gets a phantom second unit.
        DB::transaction(function () use ($capture, $as): void {
            $fresh = ScanCapture::query()->whereKey($capture->id)->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->status->applied()
                || $fresh->status === ScanCaptureStatus::Dismissed) {
                return;
            }

            $item = $this->pantry->purchase(
                $capture->user,
                $capture->matchedProduct,
                1.0,
                QuantityUnit::Unit,
                ['scan_capture_id' => $capture->id],
            );

            $eventId = null;

            if ($fresh->eat_now) {
                $eventId = $this->consumption->consumePantryItem($capture->user, $item, 1.0)->id;
            }

            $fresh->update([
                'status' => $as,
                'pantry_item_id' => $item->id,
                'consumption_event_id' => $eventId,
            ]);
            $capture->refresh();
        });
    }
}
