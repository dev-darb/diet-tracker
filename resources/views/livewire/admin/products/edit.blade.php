<?php

use App\Enums\ProductVerificationStatus;
use App\Nutrition\Nutrient;
use App\Nutrition\NutrientGroup;
use App\Nutrition\NutrientRegistry;
use App\ValueObjects\NutrientValues;
use App\Enums\ServingBasis;
use App\Models\CanonicalProduct;
use App\Services\CanonicalProductService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin: edit a canonical product's identity and attach further nutrition
 * versions (BUILD_PLAN §11). Thin — delegates every write to
 * CanonicalProductService.
 */
new #[Layout('components.layouts.admin', ['title' => 'Edit product'])] class extends Component {
    public CanonicalProduct $product;

    // Canonical identity
    public string $brand = '';
    public string $name = '';
    public string $variant = '';
    public string $gtin = '';
    public ?string $pack_size_value = null;
    public string $pack_size_unit = 'g';
    public string $category = '';

    // Add-a-version form
    public string $serving_basis = ServingBasis::Per100g->value;
    public ?string $serving_size_value = null;
    public string $serving_size_unit = 'g';
    /**
     * Every tracked nutrient, keyed by registry key. A blank entry is
     * UNKNOWN and is persisted as NULL — this form must never invent a zero
     * (audit D7).
     *
     * @var array<string, string|null>
     */
    public array $nutrients = [];
    public string $ingredients = '';
    public string $allergens = '';
    public string $status = ProductVerificationStatus::Verified->value;
    public string $confidence = '1.0';

    public function mount(CanonicalProduct $product): void
    {
        $this->product = $product;
        $this->brand = $product->brand;
        $this->name = $product->name;
        $this->variant = $product->variant ?? '';
        $this->gtin = $product->gtin ?? '';
        $this->pack_size_value = $product->pack_size_value !== null ? (string) $product->pack_size_value : null;
        $this->pack_size_unit = $product->pack_size_unit ?? 'g';
        $this->category = $product->category ?? '';
    }

    public function saveIdentity(CanonicalProductService $service): void
    {
        $data = $this->validate([
            'brand' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'gtin' => ['nullable', 'string', 'max:64', Rule::unique('canonical_products', 'gtin')->ignore($this->product->id)],
            'pack_size_value' => ['nullable', 'numeric', 'min:0'],
            'pack_size_unit' => ['nullable', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $service->updateProduct($this->product, [
            'brand' => $data['brand'],
            'name' => $data['name'],
            'variant' => $data['variant'] ?: null,
            'gtin' => $data['gtin'] ?: null,
            'pack_size_value' => $data['pack_size_value'] !== null && $data['pack_size_value'] !== '' ? (float) $data['pack_size_value'] : null,
            'pack_size_unit' => $data['pack_size_unit'] ?: null,
            'category' => $data['category'] ?: null,
        ]);

        $this->dispatch('saved');
    }

    public function addVersion(CanonicalProductService $service): void
    {
        $nutrient = ['nullable', 'numeric', 'min:0', 'max:999999'];

        $data = $this->validate([
            'serving_basis' => ['required', Rule::enum(ServingBasis::class)],
            'serving_size_value' => ['nullable', 'numeric', 'min:0', Rule::requiredIf($this->serving_basis === ServingBasis::PerServing->value)],
            'serving_size_unit' => ['nullable', 'string', 'max:16'],
            'nutrients.*' => $nutrient,
            'ingredients' => ['nullable', 'string'],
            'allergens' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(ProductVerificationStatus::class)],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $service->addVersion($this->product, [
            'serving_basis' => ServingBasis::from($data['serving_basis']),
            'serving_size_value' => $data['serving_size_value'] !== null && $data['serving_size_value'] !== '' ? (float) $data['serving_size_value'] : null,
            'serving_size_unit' => $data['serving_size_unit'] ?: null,
            ...$this->statedNutrients(),
            'ingredients' => $data['ingredients'] ?: null,
            'allergens' => $data['allergens'] ?? '',
            'status' => ProductVerificationStatus::from($data['status']),
            'confidence' => (float) $data['confidence'],
        ]);

        $this->reset([
            'serving_size_value', 'nutrients', 'ingredients', 'allergens',
        ]);

        $this->dispatch('saved');
    }


    /**
     * The nutrient map as figures, with every blank left as NULL.
     *
     * Livewire hands back an empty string for a cleared number input, and
     * `(float) ''` is 0.0 — which is exactly how this form used to claim that a
     * product contains no fibre because nobody typed a number (audit D7). An
     * unstated nutrient is unknown, and it stays unknown.
     *
     * @return array<string, float|null>
     */
    private function statedNutrients(): array
    {
        $stated = [];

        foreach ($this->nutrients as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $stated[$key] = (float) $value;
            }
        }

        // fromStated() treats every absent key as unknown, so the result carries
        // an explicit null for each nutrient nobody filled in.
        return NutrientValues::fromStated($stated)->toArray();
    }

    /** @return array<string, mixed> */
    private function nutrientFieldGroups(): array
    {
        return [
            'macroFields' => array_values(array_filter(
                NutrientRegistry::all(),
                static fn (Nutrient $n): bool => $n->group === NutrientGroup::Macro,
            )),
            'microFields' => array_values(array_filter(
                NutrientRegistry::all(),
                static fn (Nutrient $n): bool => $n->group === NutrientGroup::Micro,
            )),
        ];
    }

    public function with(): array
    {
        return [
            ...$this->nutrientFieldGroups(),
            'versions' => $this->product->versions()->with('sources')->latest('effective_from')->latest('id')->get(),
            'basisOptions' => ServingBasis::options(),
            'statusOptions' => ProductVerificationStatus::options(),
        ];
    }
}; ?>

    <div class="mx-auto max-w-2xl space-y-6" x-data="{ saved: false }"
         x-on:saved.window="saved = true; setTimeout(() => saved = false, 2500)">
        <div class="flex items-center gap-2 text-sm text-ink-dim">
            <a href="{{ route('admin.products.index') }}" class="hover:text-ink">Products</a>
            <span>/</span>
            <span class="truncate text-ink">{{ $product->brand }} — {{ $product->name }}</span>
        </div>

        @if (session('status'))
            <div class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ session('status') }}</div>
        @endif

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Edit product</h1>
            <span x-show="saved" x-cloak class="text-sm font-medium text-emerald-600">Saved</span>
        </div>

        {{-- Identity --}}
        <form wire:submit="saveIdentity" class="space-y-4">
            @include('livewire.admin.products._identity-fields')
            <button type="submit" class="rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                Save identity
            </button>
        </form>

        {{-- Existing versions --}}
        <section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-ink">Nutrition versions</h2>
            <div class="mt-3 space-y-3">
                @forelse ($versions as $version)
                    <div class="rounded-xl border border-seam bg-plate-well p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-ink">
                                {{ $version->serving_basis->label() }}
                                @if ($version->serving_size_value)
                                    <span class="font-normal text-ink-dim">· serving {{ rtrim(rtrim((string) $version->serving_size_value, '0'), '.') }}{{ $version->serving_size_unit }}</span>
                                @endif
                            </p>
                            <span class="rounded-full bg-plate px-2.5 py-0.5 text-xs font-medium text-ink-dim ring-1 ring-seam">{{ $version->status->label() }}</span>
                        </div>
                        <p class="mt-2 text-xs text-ink-dim">
                            {{ (float) $version->calories }} kcal · P {{ (float) $version->protein }}g · C {{ (float) $version->carbs }}g · F {{ (float) $version->fat }}g · Salt {{ (float) $version->salt }}g
                        </p>
                        <p class="mt-1 text-[11px] text-ink-faint">
                            Effective {{ $version->effective_from?->format('j M Y') }} ·
                            {{ $version->sources->map(fn ($s) => $s->source_type->label())->unique()->implode(', ') ?: 'no source' }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-ink-dim">No versions yet — add one below.</p>
                @endforelse
            </div>
        </section>

        {{-- Add a version --}}
        <section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-ink">Add a version</h2>
            <form wire:submit="addVersion" class="mt-4 space-y-4">
                @include('livewire.admin.products._version-fields')
                <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                    Add version
                </button>
            </form>
        </section>
    </div>

