<?php

use App\Enums\ProductVerificationStatus;
use App\Nutrition\Nutrient;
use App\Nutrition\NutrientGroup;
use App\Nutrition\NutrientRegistry;
use App\ValueObjects\NutrientValues;
use App\Enums\ServingBasis;
use App\Services\CanonicalProductService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin: create a canonical product, optionally with an initial nutrition
 * version (BUILD_PLAN §11, §20 Phase 1 manual creation). Thin — validates then
 * delegates to CanonicalProductService, which records `user_confirmed`
 * provenance for the version.
 */
new #[Layout('components.layouts.admin', ['title' => 'New product'])] class extends Component {
    // Canonical identity
    public string $brand = '';
    public string $name = '';
    public string $variant = '';
    public string $gtin = '';
    public ?string $pack_size_value = null;
    public string $pack_size_unit = 'g';
    public string $category = '';

    // Optional initial version
    public bool $withVersion = true;
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

    public function save(CanonicalProductService $service): void
    {
        $data = $this->validate($this->rules());

        $productData = [
            'brand' => $data['brand'],
            'name' => $data['name'],
            'variant' => $data['variant'] ?: null,
            'gtin' => $data['gtin'] ?: null,
            'pack_size_value' => $data['pack_size_value'] !== null && $data['pack_size_value'] !== '' ? (float) $data['pack_size_value'] : null,
            'pack_size_unit' => $data['pack_size_unit'] ?: null,
            'category' => $data['category'] ?: null,
        ];

        $versionData = $this->withVersion ? $this->versionPayload($data) : null;

        $product = $service->createProduct($productData, $versionData);

        session()->flash('status', 'Product created.');

        $this->redirectRoute('admin.products.edit', $product);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $rules = [
            'brand' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'gtin' => ['nullable', 'string', 'max:64', Rule::unique('canonical_products', 'gtin')],
            'pack_size_value' => ['nullable', 'numeric', 'min:0'],
            'pack_size_unit' => ['nullable', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->withVersion) {
            $rules = [...$rules, ...$this->versionRules()];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function versionRules(): array
    {
        $nutrient = ['nullable', 'numeric', 'min:0', 'max:999999'];

        return [
            'serving_basis' => ['required', Rule::enum(ServingBasis::class)],
            'serving_size_value' => ['nullable', 'numeric', 'min:0', Rule::requiredIf($this->serving_basis === ServingBasis::PerServing->value)],
            'serving_size_unit' => ['nullable', 'string', 'max:16'],
            'nutrients.*' => $nutrient,
            'ingredients' => ['nullable', 'string'],
            'allergens' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(ProductVerificationStatus::class)],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function versionPayload(array $data): array
    {
        return [
            'serving_basis' => ServingBasis::from($data['serving_basis']),
            'serving_size_value' => $data['serving_size_value'] !== null && $data['serving_size_value'] !== '' ? (float) $data['serving_size_value'] : null,
            'serving_size_unit' => $data['serving_size_unit'] ?: null,
            ...$this->statedNutrients(),
            'ingredients' => $data['ingredients'] ?: null,
            'allergens' => $data['allergens'] ?? '',
            'status' => ProductVerificationStatus::from($data['status']),
            'confidence' => (float) $data['confidence'],
        ];
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
            'basisOptions' => ServingBasis::options(),
            'statusOptions' => ProductVerificationStatus::options(),
        ];
    }
}; ?>

    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex items-center gap-2 text-sm text-ink-dim">
            <a href="{{ route('admin.products.index') }}" class="hover:text-ink">Products</a>
            <span>/</span>
            <span class="text-ink">New</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight text-ink">New product</h1>

        <form wire:submit="save" class="space-y-6">
            @include('livewire.admin.products._identity-fields')

            {{-- Initial nutrition version --}}
            <section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
                <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                    <input type="checkbox" wire:model.live="withVersion" class="rounded border-seam-strong text-emerald-600 focus:ring-emerald-500">
                    Add nutrition version now
                </label>

                @if ($withVersion)
                    <div class="mt-4">
                        @include('livewire.admin.products._version-fields')
                    </div>
                @endif
            </section>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                    Create product
                </button>
                <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-ink-dim hover:text-ink">Cancel</a>
            </div>
        </form>
    </div>

