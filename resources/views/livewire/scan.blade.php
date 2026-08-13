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
new class extends Component
{
    use WithFileUploads;

    /** capture | confirm | quantity | done | unknown | corrected | ai_unavailable */
    public string $step = 'capture';

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
        $this->validate(['photo' => ['required', 'image', 'max:8192']]);

        // Store the capture on the local public disk (retention policy is M8).
        $this->imagePath = $this->photo->store('scans', 'public');

        $barcode = trim($this->detectedBarcode) ?: null;
        $meta = [];

        if ($barcode !== null) {
            // Fast path: deterministic barcode -> OFF, no AI cost (idea #1, §7.15).
            $detected = IdentifiedProduct::fromArray(['barcode' => $barcode, 'confidence' => 1.0]);
        } else {
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

        $result = $resolver->resolve($detected, Auth::user(), $meta);

        // Keep the captured image on the audit row (brief §12).
        $result->resolutionJob->update(['uploaded_image_path' => $this->imagePath]);

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
            'matchedProductId', 'isSuggestion', 'detected', 'quantity', 'unit', 'addedProductName',
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
        $summary = $product !== null ? $nutrition->productSummary($product) : null;

        return [
            'product' => $product,
            'nutrition' => $summary !== null ? $summary['values']->rounded(1) : null,
            'nutritionBasis' => $summary['basis_label'] ?? null,
            'unitOptions' => QuantityUnit::options(),
        ];
    }
}; ?>

<x-layouts.app :title="__('Scan')">
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Scan</h1>
            <p class="mt-1 text-sm text-zinc-500">One packaged product at a time. Show the front of the pack clearly.</p>
        </div>

        {{-- Progress spinner while resolving (brief §7.2, §15). --}}
        <div wire:loading wire:target="analyze" class="flex flex-col items-center justify-center rounded-2xl border border-zinc-100 bg-white px-6 py-16 text-center shadow-sm">
            <svg class="size-8 animate-spin text-emerald-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="mt-4 text-sm font-medium text-zinc-700">Identifying your product…</p>
            <p class="mt-1 text-xs text-zinc-500">Checking the barcode and product database.</p>
        </div>

        <div wire:loading.remove wire:target="analyze">

            {{-- STEP 1 — Capture --------------------------------------------------}}
            @if ($step === 'capture')
                <div class="space-y-4"
                     x-data="{ preview: null }">
                    <label class="block cursor-pointer">
                        <div class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50/60 px-6 py-12 text-center transition hover:border-emerald-300 hover:bg-emerald-50/40">
                            <template x-if="preview">
                                <img :src="preview" alt="Selected product" class="mb-4 max-h-48 rounded-xl object-contain shadow-sm">
                            </template>
                            <template x-if="!preview">
                                <div class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-white text-emerald-600 shadow-sm ring-1 ring-zinc-100">
                                    <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                                    </svg>
                                </div>
                            </template>
                            <p class="text-sm font-semibold text-zinc-900" x-text="preview ? 'Photo ready' : 'Take or upload a photo'"></p>
                            <p class="mt-1 text-xs text-zinc-500">Use your camera, or choose an existing image.</p>
                        </div>
                        <input type="file" accept="image/*" capture="environment" class="sr-only"
                               wire:model="photo"
                               x-on:change="
                                    const f = $event.target.files[0];
                                    preview = f ? URL.createObjectURL(f) : null;
                                    $wire.set('detectedBarcode', '');
                                    if (f && window.detectBarcode) {
                                        window.detectBarcode(f).then(code => { if (code) $wire.set('detectedBarcode', code); }).catch(() => {});
                                    }
                               ">
                    </label>

                    @error('photo') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    @if ($detectedBarcode !== '')
                        <div class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-700">
                            <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5v15m3-15v15m3-15v15m4.5-15v15m3-15v15" /></svg>
                            <span>Barcode detected on-device — <span class="font-semibold tabular-nums">{{ $detectedBarcode }}</span></span>
                        </div>
                    @endif

                    @if ($photo)
                        <button type="button" wire:click="analyze"
                                class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Identify product
                        </button>
                    @endif

                    <p class="text-center text-xs text-zinc-400">
                        Prefer to type it in? <a href="{{ route('pantry') }}" wire:navigate class="font-medium text-emerald-600 hover:text-emerald-700">Add to pantry manually</a>
                    </p>
                </div>
            @endif

            {{-- STEP 2 — Confirm "Is this right?" (brief §7.6) --------------------}}
            @if ($step === 'confirm' && $product)
                <div class="space-y-5">
                    <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Is this right?</p>

                        <h2 class="mt-2 text-lg font-semibold text-zinc-900">{{ $product->brand }}</h2>
                        <p class="text-sm text-zinc-700">{{ $product->name }}</p>
                        @if ($product->variant)
                            <p class="text-sm text-zinc-500">{{ $product->variant }}</p>
                        @endif
                        @if ($product->pack_size_value)
                            <p class="mt-0.5 text-xs text-zinc-400">{{ rtrim(rtrim(number_format((float) $product->pack_size_value, 3, '.', ''), '0'), '.') }}{{ $product->pack_size_unit }}</p>
                        @endif

                        @if ($isSuggestion)
                            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">This is our best guess, not a certain match. Please check it's correct.</p>
                        @endif

                        {{-- Key macros (computed by the nutrition service, never inline maths) --}}
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            @php
                                $kcal = $nutrition?->calories;
                                $protein = $nutrition?->protein;
                            @endphp
                            <div class="rounded-xl bg-zinc-50 px-3 py-2.5">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Calories</p>
                                <p class="mt-0.5 text-sm font-semibold text-zinc-900">
                                    {{ $kcal !== null ? rtrim(rtrim(number_format($kcal, 1, '.', ''), '0'), '.').' kcal' : 'Not available' }}
                                </p>
                            </div>
                            <div class="rounded-xl bg-zinc-50 px-3 py-2.5">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Protein</p>
                                <p class="mt-0.5 text-sm font-semibold text-zinc-900">
                                    {{ $protein !== null ? rtrim(rtrim(number_format($protein, 1, '.', ''), '0'), '.').' g' : 'Not available' }}
                                </p>
                            </div>
                        </div>
                        @if ($nutritionBasis)
                            <p class="mt-2 text-[11px] text-zinc-400">{{ $nutritionBasis }}</p>
                        @elseif (! $nutrition)
                            <p class="mt-2 text-[11px] text-zinc-400">Nutrition isn't available for this product yet.</p>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="yesAddIt"
                                class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Yes, add it
                        </button>
                        <button type="button" wire:click="wrongProduct"
                                class="w-full rounded-xl border border-zinc-200 px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                            Wrong product
                        </button>
                    </div>
                </div>
            @endif

            {{-- STEP 3 — Quantity (brief §7.7) -----------------------------------}}
            @if ($step === 'quantity' && $product)
                <div class="space-y-5">
                    <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-zinc-900">How many did you buy?</h2>
                        <p class="mt-0.5 text-xs text-zinc-500">{{ $product->brand }} — {{ $product->name }}</p>

                        <div class="mt-4 flex items-center justify-center gap-5">
                            <button type="button" wire:click="decrement" aria-label="Decrease"
                                    class="flex size-11 items-center justify-center rounded-full border border-zinc-200 text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50">
                                −
                            </button>
                            <span class="w-12 text-center text-3xl font-bold tabular-nums text-zinc-900">{{ $quantity }}</span>
                            <button type="button" wire:click="increment" aria-label="Increase"
                                    class="flex size-11 items-center justify-center rounded-full border border-zinc-200 text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50">
                                +
                            </button>
                        </div>

                        <div class="mt-4">
                            <label class="text-xs font-medium text-zinc-600">Unit</label>
                            <select wire:model="unit"
                                    class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                @foreach ($unitOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('quantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('unit') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <button type="button" wire:click="addToPantry"
                            class="w-full rounded-xl bg-zinc-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800">
                        Add to pantry
                    </button>
                </div>
            @endif

            {{-- STEP 4 — Done ---------------------------------------------------}}
            @if ($step === 'done')
                <div class="space-y-5">
                    <div class="flex flex-col items-center rounded-2xl border border-zinc-100 bg-white px-6 py-12 text-center shadow-sm">
                        <div class="flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                            <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </div>
                        <h2 class="mt-4 text-lg font-semibold text-zinc-900">Added to your pantry</h2>
                        <p class="mt-1 text-sm text-zinc-500">{{ $addedProductName }}</p>
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="scanAnother"
                                class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Scan another
                        </button>
                        <a href="{{ route('pantry') }}" wire:navigate
                           class="block w-full rounded-xl border border-zinc-200 px-4 py-3 text-center text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                            View pantry
                        </a>
                    </div>
                </div>
            @endif

            {{-- Unknown / needs-research fallback (research is Milestone 3) ------}}
            @if ($step === 'unknown')
                <div class="space-y-5">
                    <div class="flex flex-col items-center rounded-2xl border border-zinc-100 bg-white px-6 py-12 text-center shadow-sm">
                        <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-500">
                            <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>
                        </div>
                        <h2 class="mt-4 text-lg font-semibold text-zinc-900">We couldn't confidently identify this yet</h2>
                        <p class="mt-1 max-w-xs text-sm text-zinc-500">Scanning the barcode usually works best. You can also add this product to your pantry manually.</p>
                    </div>

                    <div class="space-y-2">
                        <a href="{{ route('pantry') }}" wire:navigate
                           class="block w-full rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Add manually
                        </a>
                        <button type="button" wire:click="scanAnother"
                                class="w-full rounded-xl border border-zinc-200 px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                            Try another photo
                        </button>
                    </div>
                </div>
            @endif

            {{-- Photo identification needs AI configuration (key-absent grace) ---}}
            @if ($step === 'ai_unavailable')
                <div class="space-y-5">
                    <div class="flex flex-col items-center rounded-2xl border border-zinc-100 bg-white px-6 py-12 text-center shadow-sm">
                        <div class="flex size-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-600">
                            <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        </div>
                        <h2 class="mt-4 text-lg font-semibold text-zinc-900">Photo identification needs AI configuration</h2>
                        <p class="mt-1 max-w-xs text-sm text-zinc-500">Scan the barcode instead, or add this product to your pantry manually.</p>
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="scanAnother"
                                class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Scan the barcode
                        </button>
                        <a href="{{ route('pantry') }}" wire:navigate
                           class="block w-full rounded-xl border border-zinc-200 px-4 py-3 text-center text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                            Add manually
                        </a>
                    </div>
                </div>
            @endif

            {{-- Wrong product recorded — retry or manual (brief §7.6) -----------}}
            @if ($step === 'corrected')
                <div class="space-y-5">
                    <div class="flex flex-col items-center rounded-2xl border border-zinc-100 bg-white px-6 py-12 text-center shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Thanks — we've noted that</h2>
                        <p class="mt-1 max-w-xs text-sm text-zinc-500">Your correction helps improve product matching. Try another photo, or add the product manually.</p>
                    </div>

                    <div class="space-y-2">
                        <button type="button" wire:click="scanAnother"
                                class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Try another photo
                        </button>
                        <a href="{{ route('pantry') }}" wire:navigate
                           class="block w-full rounded-xl border border-zinc-200 px-4 py-3 text-center text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                            Add manually
                        </a>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-layouts.app>
