<?php

use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ProductResolutionJob;
use App\Services\PantryNutritionService;
use App\Services\PantryService;
use App\Services\ProductResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Scan flow (BUILD_PLAN J2.5; brief §7.2–§7.7, §16.2). One packaged product at a
 * time: capture/upload -> on-device barcode fast path -> resolve (with progress)
 * -> "Is this right?" confirm/correct -> quantity -> add to pantry.
 *
 * THIN by design (brief §4.1): this component only orchestrates steps and calls
 * services. It never does nutrient arithmetic (PantryNutritionService), product
 * resolution (ProductResolver), or ledger writes (PantryService), and it never
 * touches an AI SDK type — visual identification goes through the ProductIdentifier
 * contract, so the model/gateway stays swappable.
 *
 * Grace paths:
 *  - No barcode + no AI key -> a friendly "photo identification needs AI
 *    configuration" state (never a 500). The barcode + manual paths keep working.
 *  - Unknown / needs-research result -> a friendly manual-add fallback (the async
 *    research workflow is Milestone 3, deliberately not built here).
 */
new #[Layout('components.layouts.app', ['title' => 'Scan'])] class extends Component
{
    use WithFileUploads;

    /** capture | confirm | quantity | done | unknown | corrected | ai_unavailable | error */
    public string $step = 'capture';

    /** Human-readable detail shown on the `error` step to aid alpha debugging. */
    public string $errorDetail = '';

    // Capture
    public $photo = null;

    /** Barcode read on-device by JS (BarcodeDetector / ZXing); '' when none found. */
    public string $detectedBarcode = '';

    // Resolution outcome
    public ?string $imagePath = null;

    public ?int $resolutionJobId = null;

    public ?int $matchedProductId = null;

    public bool $isSuggestion = false;

    /** @var array<string, mixed> the fields we resolved from (barcode or AI). */
    public array $detected = [];

    // Quantity
    public int $quantity = 1;

    public string $unit = 'unit';

    // Success
    public string $addedProductName = '';

    /**
     * Capture -> identify -> resolve. Barcode (read on-device) takes the keyless
     * Open Food Facts fast path; otherwise the photo goes to AI identification.
     */
    public function analyze(ProductIdentifier $identifier, ProductResolver $resolver): void
    {
        $this->resetValidation();

        $barcode = trim($this->detectedBarcode) ?: null;

        // The barcode is read on-device, so a barcode scan needs NO uploaded image
        // (the stored photo is only an audit artefact) — this keeps the keyless
        // fast path working even when the browser file upload is unavailable. The
        // photo -> AI path, by contrast, genuinely needs the image.
        if ($barcode === null) {
            // Downscaled to JPEG on-device before upload, so this stays small; the
            // ceiling is generous only for the rare original-file fallback.
            $this->validate(['photo' => ['required', 'image', 'max:12288']]);
        } elseif ($this->photo !== null) {
            $this->validate(['photo' => ['image', 'max:12288']]);
        }

        // Store the capture for the audit trail when we actually have one (best
        // effort — a storage hiccup must never sink a valid barcode scan).
        if ($this->photo !== null) {
            try {
                $this->imagePath = $this->photo->store('scans', 'public');
            } catch (Throwable $e) {
                report($e);
                $this->imagePath = null;
            }
        }

        $meta = [];

        if ($barcode !== null) {
            // Fast path: deterministic barcode -> OFF, no AI cost (idea #1, §7.15).
            $detected = IdentifiedProduct::fromArray(['barcode' => $barcode, 'confidence' => 1.0]);
        } else {
            // Photo path needs the stored image; if storage failed, degrade gracefully.
            if ($this->imagePath === null) {
                $this->step = 'ai_unavailable';

                return;
            }

            // Photo path: multimodal AI identification (needs a provider key).
            try {
                $detected = $identifier->identify(ProductImage::fromStoragePath($this->imagePath, 'public'));
            } catch (Throwable $e) {
                // KEY-ABSENT GRACE: no OPENROUTER_API_KEY -> friendly message, not a 500.
                report($e);
                $this->step = 'ai_unavailable';

                return;
            }

            $config = config('ai.product_identifier');
            $meta = ['model_provider' => $config['provider'] ?? null, 'model_name' => $config['model'] ?? null];
        }

        try {
            $result = $resolver->resolve($detected, Auth::user(), $meta);

            // Keep the captured image on the audit row (brief §12).
            $result->resolutionJob->update(['uploaded_image_path' => $this->imagePath]);
        } catch (Throwable $e) {
            // A real product-data / import failure must not blank the screen —
            // report it and show an actionable error instead of a 500.
            report($e);
            $this->errorDetail = $e->getMessage();
            $this->step = 'error';

            return;
        }

        $this->resolutionJobId = $result->resolutionJob->id;
        $this->detected = $detected->toArray();

        if ($result->canonicalProduct !== null) {
            // A match OR a low-confidence suggestion — either way, ask the user.
            $this->matchedProductId = $result->canonicalProduct->id;
            $this->isSuggestion = $result->isSuggestion();
            $this->step = 'confirm';

            return;
        }

        // Needs research (Milestone 3): show the manual fallback for now.
        $this->step = 'unknown';
    }

    /** "Yes, add it" — proceed to quantity (brief §7.6/§7.7). */
    public function yesAddIt(): void
    {
        if ($this->matchedProductId === null) {
            return;
        }

        $this->step = 'quantity';
    }

    /**
     * "Wrong product" — record the rejection as evidence (corrections are
     * valuable product intelligence, brief §7.6, §11) then offer retry/manual.
     */
    public function wrongProduct(): void
    {
        if ($this->resolutionJobId !== null) {
            $job = ProductResolutionJob::find($this->resolutionJobId);

            if ($job !== null) {
                $job->user_correction = [
                    'rejected_product_id' => $this->matchedProductId,
                    'was_suggestion' => $this->isSuggestion,
                    'detected_fields' => $this->detected,
                ];
                $job->corrected_at = now();
                $job->save();
            }
        }

        $this->step = 'corrected';
    }

    public function decrement(): void
    {
        $this->quantity = max(1, $this->quantity - 1);
    }

    public function increment(): void
    {
        $this->quantity++;
    }

    /** Add the confirmed product to the pantry via the ledger service (brief §7.7). */
    public function addToPantry(PantryService $service): void
    {
        $data = $this->validate([
            'quantity' => ['required', 'integer', 'gt:0'],
            'unit' => ['required', Rule::enum(QuantityUnit::class)],
        ]);

        $product = CanonicalProduct::findOrFail($this->matchedProductId);

        $service->purchase(
            Auth::user(),
            $product,
            (float) $data['quantity'],
            QuantityUnit::from($data['unit']),
        );

        $this->addedProductName = trim($product->brand.' — '.$product->name, ' —');
        $this->step = 'done';
    }

    /** Reset to a fresh capture ("Scan another"). */
    public function scanAnother(): void
    {
        $this->reset([
            'photo', 'detectedBarcode', 'imagePath', 'resolutionJobId',
            'matchedProductId', 'isSuggestion', 'detected', 'quantity', 'unit', 'addedProductName', 'errorDetail',
        ]);
        $this->quantity = 1;
        $this->unit = QuantityUnit::Unit->value;
        $this->step = 'capture';
        $this->resetValidation();
    }

    /** Go back to capture from a match we want to re-take. */
    public function retry(): void
    {
        $this->scanAnother();
    }

    public function with(PantryNutritionService $nutrition): array
    {
        $product = $this->matchedProductId !== null ? CanonicalProduct::find($this->matchedProductId) : null;

        // Never let a nutrition-summary edge case blank the confirm screen — the
        // product identity is what matters here; macros degrade to "not available".
        try {
            $summary = $product !== null ? $nutrition->productSummary($product) : null;
        } catch (Throwable $e) {
            report($e);
            $summary = null;
        }

        return [
            'product' => $product,
            'nutrition' => $summary !== null ? $summary['values']->rounded(1) : null,
            'nutritionBasis' => $summary['basis_label'] ?? null,
            'unitOptions' => QuantityUnit::options(),
            // Reward-strip counter on the done step (design brief: reward the loop).
            'pantryCount' => $this->step === 'done'
                ? Auth::user()->pantryItems()->where('current_quantity', '>', 0)->count()
                : null,
        ];
    }
}; ?>

    <div class="space-y-3">
        <style>[x-cloak]{display:none!important}</style>

        {{-- Progress while resolving (brief §7.2, §15). --}}
        <div wire:loading wire:target="analyze" class="module px-6 py-14 text-center">
            <div class="led-sweep mx-auto flex w-fit gap-1.5" aria-hidden="true">
                @for ($i = 0; $i < 10; $i++)
                    <span class="led led-on"></span>
                @endfor
            </div>
            <p class="silkscreen mt-5">Identifying</p>
            <p class="mt-2 text-sm text-ink-dim">Checking the barcode and product database…</p>
        </div>

        <div wire:loading.remove wire:target="analyze" class="space-y-3">

            {{-- STEP 1 — Capture --------------------------------------------------}}
            @if ($step === 'capture')
                <div class="space-y-3"
                     x-data="{
                        preview: null,
                        reading: false,
                        barcodeFound: false,
                        barcode: '',
                        uploading: false,
                        uploaded: false,
                        progress: 0,
                        uploadError: null,
                        async handle(event) {
                            const file = event.target.files[0];
                            this.preview = null;
                            this.reading = false;
                            this.barcodeFound = false;
                            this.barcode = '';
                            this.uploading = false;
                            this.uploaded = false;
                            this.progress = 0;
                            this.uploadError = null;
                            if (!file) { return; }
                            this.preview = URL.createObjectURL(file);
                            // Clear any barcode from a previous scan on the server.
                            $wire.set('detectedBarcode', '');

                            // 1) Read a barcode on-device. If found, the keyless Open Food Facts
                            //    lookup needs NO file upload — so this path works even when the
                            //    browser upload is unavailable. Persist it to the server now so a
                            //    plain wire:click on the button can resolve it.
                            if (window.detectBarcode) {
                                this.reading = true;
                                let code = null;
                                try {
                                    code = await Promise.race([
                                        window.detectBarcode(file),
                                        new Promise((r) => setTimeout(() => r(null), 8000)),
                                    ]);
                                } catch (_) {}
                                this.reading = false;
                                if (code) { this.barcode = code; this.barcodeFound = true; $wire.set('detectedBarcode', code); return; }
                            }

                            // 2) No barcode → the photo itself must be uploaded for AI identification.
                            this.uploading = true;
                            try {
                                const upload = window.downscaleImage ? await window.downscaleImage(file) : file;
                                let done = false;
                                const watchdog = setTimeout(() => {
                                    if (done) return;
                                    this.uploading = false;
                                    this.uploadError = 'Photo upload timed out. Add the product manually below, or scan its barcode (which needs no upload).';
                                }, 20000);
                                $wire.upload('photo', upload,
                                    () => { done = true; clearTimeout(watchdog); this.uploading = false; this.uploaded = true; },
                                    (message) => { done = true; clearTimeout(watchdog); this.uploading = false; this.uploadError = 'Photo upload was rejected' + (message ? ' (' + message + ')' : '') + '. Add manually below, or scan the barcode instead.'; },
                                    (e) => { this.progress = (e && e.detail) ? e.detail.progress : this.progress; }
                                );
                            } catch (err) {
                                this.uploading = false;
                                this.uploadError = 'Photo upload error: ' + (err && err.message ? err.message : err);
                            }
                        }
                     }">

                    {{-- The capture well: a viewfinder, not a form. --}}
                    <label class="module block cursor-pointer px-5 pb-5 pt-4">
                        <span class="silkscreen">Scan</span>
                        <div class="relative mt-3 flex min-h-56 flex-col items-center justify-center overflow-hidden rounded-[5px] border border-seam bg-plate-well px-6 py-10 text-center transition hover:border-seam-strong">
                            {{-- Viewfinder corner brackets --}}
                            <svg class="pointer-events-none absolute inset-2 text-seam-strong" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M0 8V0h4M96 0h4v8M100 92v8h-4M4 100H0v-8" fill="none" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" transform="scale(1,1)"/>
                            </svg>
                            <template x-if="preview">
                                <img :src="preview" alt="Selected product" class="mb-4 max-h-44 rounded object-contain">
                            </template>
                            <template x-if="!preview">
                                <svg class="mb-4 size-9 text-ink-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                                    <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
                                    <path d="M6.5 12h0.01M9.5 12h0.01M12.5 12h0.01M15.5 12h0.01M18 12h-0.01" stroke-width="2" />
                                </svg>
                            </template>
                            <p class="voice-caption font-medium text-ink" x-text="preview ? 'Photo ready' : 'Take or upload a photo'"></p>
                            <p class="voice-caption mt-1 text-ink-dim">One packaged product at a time — show the front of the pack.</p>
                        </div>
                        <input type="file" accept="image/*" capture="environment" class="sr-only"
                               x-on:change="handle($event)">
                    </label>

                    @error('photo') <p class="px-1 text-xs text-high">{{ $message }}</p> @enderror

                    {{-- On-device barcode read (needs no upload). --}}
                    <p x-show="reading" x-cloak class="data px-1 text-xs text-ink-dim">READING BARCODE…</p>

                    <div x-show="barcodeFound" x-cloak class="module flex items-center gap-3 px-4 py-3">
                        <span class="size-2 shrink-0 rounded-full bg-good" aria-hidden="true"></span>
                        <p class="data-sm min-w-0 truncate text-ink">BARCODE <span x-text="barcode"></span> <span class="text-ink-faint">· ON-DEVICE</span></p>
                    </div>

                    {{-- Photo upload — only the AI photo path needs this. --}}
                    <div x-show="uploading" x-cloak class="module space-y-2 px-4 py-3">
                        <div class="data-sm flex items-center justify-between text-ink-dim">
                            <span>UPLOADING PHOTO</span>
                            <span x-text="progress + '%'"></span>
                        </div>
                        <div class="meter"><span class="!bg-action" :style="`width: ${progress}%`"></span></div>
                    </div>

                    <p x-show="uploadError" x-cloak class="border-l border-high bg-plate-well px-3 py-2 text-xs leading-relaxed text-ink-dim" x-text="uploadError"></p>

                    <button type="button" x-show="barcodeFound || uploaded" x-cloak
                            wire:click="analyze"
                            class="key key-action w-full keycap px-4 py-3.5 text-center">
                        Identify product
                    </button>

                    <p class="px-1 text-center text-xs text-ink-faint">
                        Prefer to type it in? <a href="{{ route('pantry') }}" class="text-ink-dim underline decoration-seam-strong underline-offset-4 transition hover:text-ink">Add to pantry manually</a>
                    </p>
                </div>
            @endif

            {{-- STEP 2 — Confirm "Is this right?" (brief §7.6) --------------------}}
            @if ($step === 'confirm' && $product)
                <div class="space-y-3">
                    <div class="module px-5 pb-5 pt-4">
                        <h2 class="silkscreen">Match — Is this right?</h2>

                        <p class="voice-item mt-4 text-ink">{{ $product->brand }} <span class="text-ink-dim">{{ $product->name }}</span></p>
                        @if ($product->variant)
                            <p class="mt-0.5 text-sm text-ink-dim">{{ $product->variant }}</p>
                        @endif

                        {{-- Provenance is first-class (brief §2.2). --}}
                        <p class="data-sm mt-3 text-ink-faint uppercase">
                            @if ($detectedBarcode !== '') Barcode {{ $detectedBarcode }} · @endif
                            @if ($product->pack_size_value) {{ rtrim(rtrim(number_format((float) $product->pack_size_value, 3, '.', ''), '0'), '.') }}{{ $product->pack_size_unit }} · @endif
                            {{ $isSuggestion ? 'Best guess' : 'Matched' }}
                        </p>

                        @if ($isSuggestion)
                            <p class="mt-3 border-l border-low bg-plate-well px-3 py-2 text-xs leading-relaxed text-ink-dim">
                                Best guess, not a certain match — please check it before adding.
                            </p>
                        @endif

                        {{-- Key macros (computed by the nutrition service, never inline maths) --}}
                        <div class="mt-4 grid grid-cols-2 divide-x divide-seam border-t border-seam pt-1">
                            <div class="py-2.5 pr-4">
                                <h3 class="silkscreen">Calories</h3>
                                <p class="data-lg mt-1 text-ink">
                                    @if ($nutrition?->calories !== null)
                                        {{ rtrim(rtrim(number_format($nutrition->calories, 1, '.', ''), '0'), '.') }}<span class="text-xs text-ink-dim"> KCAL</span>
                                    @else
                                        ----
                                    @endif
                                </p>
                            </div>
                            <div class="py-2.5 pl-4">
                                <h3 class="silkscreen">Protein</h3>
                                <p class="data-lg mt-1 text-ink">
                                    @if ($nutrition?->protein !== null)
                                        {{ rtrim(rtrim(number_format($nutrition->protein, 1, '.', ''), '0'), '.') }}<span class="text-xs text-ink-dim">G</span>
                                    @else
                                        ----
                                    @endif
                                </p>
                            </div>
                        </div>
                        @if ($nutritionBasis)
                            <p class="data-sm mt-1 text-ink-faint"><span class="uppercase">{{ $nutritionBasis }}</span></p>
                        @elseif (! $nutrition)
                            <p class="voice-micro mt-1 text-ink-faint">Nutrition isn't available for this product yet.</p>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="yesAddIt"
                                class="key key-action w-full keycap px-4 py-3.5 text-center">
                            Yes, add it
                        </button>
                        <button type="button" wire:click="wrongProduct"
                                class="key w-full keycap px-4 py-3.5 text-center text-ink-dim">
                            Wrong product
                        </button>
                    </div>
                </div>
            @endif

            {{-- STEP 3 — Quantity (brief §7.7) -----------------------------------}}
            @if ($step === 'quantity' && $product)
                <div class="space-y-3">
                    <div class="module px-5 pb-5 pt-4">
                        <h2 class="silkscreen">Quantity — how many did you buy?</h2>
                        <p class="mt-2 text-sm text-ink-dim">{{ $product->brand }} — {{ $product->name }}</p>

                        <div class="mt-5 flex items-center justify-center gap-4">
                            <button type="button" wire:click="decrement" aria-label="Decrease"
                                    class="key flex size-12 items-center justify-center text-lg text-ink">−</button>
                            <span class="data-xl w-24 border-b border-seam pb-1 text-center text-ink">{{ $quantity }}</span>
                            <button type="button" wire:click="increment" aria-label="Increase"
                                    class="key flex size-12 items-center justify-center text-lg text-ink">+</button>
                        </div>

                        <div class="mt-5">
                            <label class="silkscreen" for="scan-unit">Unit</label>
                            <select id="scan-unit" wire:model="unit"
                                    class="data mt-1.5 w-full rounded-[5px] border border-seam bg-plate-well px-3 py-2.5 text-sm text-ink focus:border-action focus:outline-none">
                                @foreach ($unitOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('quantity') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                        @error('unit') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                    </div>

                    <button type="button" wire:click="addToPantry"
                            class="key key-action w-full keycap px-4 py-3.5 text-center">
                        Add to pantry
                    </button>
                </div>
            @endif

            {{-- Something failed while resolving — actionable, never a blank page --}}
            @if ($step === 'error')
                <div class="space-y-3">
                    <div class="module px-6 py-12 text-center">
                        <p class="data-xl text-high" aria-hidden="true">ERR</p>
                        <p class="silkscreen mt-2">Resolve failed</p>
                        <h2 class="voice-item mt-5 text-ink">Something went wrong adding that product</h2>
                        <p class="voice-caption mx-auto mt-1.5 max-w-xs text-ink-dim">It's been logged. Try again, or add the product to your pantry manually.</p>
                        @if ($errorDetail !== '')
                            <p class="data-sm mx-auto mt-4 max-w-xs break-words border-l border-high bg-plate-well px-3 py-2 text-left text-ink-dim">
                                DETAIL (share with support): {{ $errorDetail }}
                            </p>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="scanAnother"
                                class="key key-action w-full keycap px-4 py-3.5 text-center">
                            Try another scan
                        </button>
                        <a href="{{ route('pantry') }}"
                           class="key block w-full keycap px-4 py-3.5 text-center text-ink-dim">
                            Add manually
                        </a>
                    </div>
                </div>
            @endif

            {{-- STEP 4 — Done: the machine stamps the win (design brief). --------}}
            @if ($step === 'done')
                <div class="space-y-3">
                    <div class="stamp-in rounded-md bg-good px-6 pb-4 pt-7 text-black">
                        <div class="flex items-center gap-5">
                            <svg class="size-20 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 12.5l5.5 5.5L20 6.5" />
                            </svg>
                            <p class="voice-display text-[2.6rem]">Added to<br>pantry</p>
                        </div>
                        <div class="led-sweep mt-6 flex justify-between" aria-hidden="true">
                            @for ($i = 0; $i < 16; $i++)
                                <span class="led led-good"></span>
                            @endfor
                        </div>
                    </div>

                    <div class="module px-5 pb-4 pt-4">
                        <h2 class="silkscreen">Item</h2>
                        <p class="voice-item mt-2 text-ink">{{ $addedProductName }}</p>
                        @php($fmtStamp = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'))
                        @php($stamp = $nutrition === null ? null : collect([
                            $nutrition->calories !== null ? $fmtStamp($nutrition->calories).' KCAL' : null,
                            $nutrition->protein !== null ? $fmtStamp($nutrition->protein).'P' : null,
                            $nutrition->carbs !== null ? $fmtStamp($nutrition->carbs).'C' : null,
                            $nutrition->fat !== null ? $fmtStamp($nutrition->fat).'F' : null,
                        ])->filter()->implode(' · '))
                        <p class="data-sm mt-2 text-ink-dim">
                            @if ($stamp)
                                {{ $stamp }}@if ($nutritionBasis) <span class="text-ink-faint uppercase">· {{ $nutritionBasis }}</span> @endif
                            @else
                                <span class="text-ink-faint">NUTRITION ----</span>
                            @endif
                        </p>
                        {{-- Provenance is first-class (brief §2.2): only facts we hold. --}}
                        <p class="data-sm mt-1.5 border-t border-seam pt-2 text-ink-faint uppercase">
                            {{ $detectedBarcode !== '' ? 'Barcode '.$detectedBarcode.' · ' : '' }}{{ $isSuggestion ? 'Best guess — confirmed by you' : 'Verified match' }}
                        </p>
                    </div>

                    {{-- Reward strip: the counters that just moved. --}}
                    <div class="flex gap-2" aria-label="Progress update">
                        <span class="chip border-action !py-2 !pl-2.5 text-action">
                            <span class="mr-1 inline-block size-2 rounded-[2px] bg-action align-baseline" aria-hidden="true"></span>
                            +1 ITEM
                        </span>
                        @if ($pantryCount !== null)
                            <span class="chip inline-flex items-center gap-1.5 border-seam-strong !py-2 text-ink-dim">
                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8" /></svg>
                                PANTRY {{ $pantryCount }} {{ $pantryCount === 1 ? 'ITEM' : 'ITEMS' }}
                            </span>
                        @endif
                    </div>

                    <div class="space-y-2 pt-1">
                        <button type="button" wire:click="scanAnother"
                                class="key key-action w-full keycap px-4 py-3.5 text-center">
                            Scan next
                        </button>
                        <a href="{{ route('pantry') }}"
                           class="key block w-full keycap px-4 py-3.5 text-center text-ink-dim">
                            Done
                        </a>
                    </div>
                </div>
            @endif

            {{-- Unknown / needs-research fallback (research is Milestone 3) ------}}
            @if ($step === 'unknown')
                <div class="space-y-3">
                    <div class="module px-6 py-12 text-center">
                        <p class="data-xl text-ink-faint" aria-hidden="true">?---</p>
                        <p class="silkscreen mt-2">No confident match</p>
                        <h2 class="voice-item mt-5 text-ink">We couldn't confidently identify this yet</h2>
                        <p class="voice-caption mx-auto mt-1.5 max-w-xs text-ink-dim">Scanning the barcode usually works best. You can also add this product to your pantry manually.</p>
                    </div>

                    <div class="space-y-2">
                        <a href="{{ route('pantry') }}"
                           class="key key-action block w-full keycap px-4 py-3.5 text-center">
                            Add manually
                        </a>
                        <button type="button" wire:click="scanAnother"
                                class="key w-full keycap px-4 py-3.5 text-center text-ink-dim">
                            Try another photo
                        </button>
                    </div>
                </div>
            @endif

            {{-- Photo identification needs AI configuration (key-absent grace) ---}}
            @if ($step === 'ai_unavailable')
                <div class="space-y-3">
                    <div class="module px-6 py-12 text-center">
                        <p class="data-xl text-low" aria-hidden="true">AI--</p>
                        <p class="silkscreen mt-2">Not configured</p>
                        <h2 class="voice-item mt-5 text-ink">Photo identification needs AI configuration</h2>
                        <p class="voice-caption mx-auto mt-1.5 max-w-xs text-ink-dim">Scan the barcode instead, or add this product to your pantry manually.</p>
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="scanAnother"
                                class="key key-action w-full keycap px-4 py-3.5 text-center">
                            Scan the barcode
                        </button>
                        <a href="{{ route('pantry') }}"
                           class="key block w-full keycap px-4 py-3.5 text-center text-ink-dim">
                            Add manually
                        </a>
                    </div>
                </div>
            @endif

            {{-- Wrong product recorded — retry or manual (brief §7.6) -----------}}
            @if ($step === 'corrected')
                <div class="space-y-3">
                    <div class="module px-6 py-12 text-center">
                        <p class="data-xl text-info" aria-hidden="true">LOGD</p>
                        <p class="silkscreen mt-2">Correction recorded</p>
                        <h2 class="voice-item mt-5 text-ink">Thanks — we've noted that</h2>
                        <p class="voice-caption mx-auto mt-1.5 max-w-xs text-ink-dim">Your correction helps improve product matching. Try another photo, or add the product manually.</p>
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="scanAnother"
                                class="key key-action w-full keycap px-4 py-3.5 text-center">
                            Try another photo
                        </button>
                        <a href="{{ route('pantry') }}"
                           class="key block w-full keycap px-4 py-3.5 text-center text-ink-dim">
                            Add manually
                        </a>
                    </div>
                </div>
            @endif

        </div>
    </div>
