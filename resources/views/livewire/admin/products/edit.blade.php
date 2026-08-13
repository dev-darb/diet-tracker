<?php

use App\Enums\ProductVerificationStatus;
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
new #[Layout('components.layouts.admin')] class extends Component {
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
    public ?string $calories = null;
    public ?string $protein = null;
    public ?string $carbs = null;
    public ?string $sugars = null;
    public ?string $fat = null;
    public ?string $saturated_fat = null;
    public ?string $fibre = null;
    public ?string $salt = null;
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
        $macro = ['nullable', 'numeric', 'min:0'];

        $data = $this->validate([
            'serving_basis' => ['required', Rule::enum(ServingBasis::class)],
            'serving_size_value' => ['nullable', 'numeric', 'min:0', Rule::requiredIf($this->serving_basis === ServingBasis::PerServing->value)],
            'serving_size_unit' => ['nullable', 'string', 'max:16'],
            'calories' => $macro, 'protein' => $macro, 'carbs' => $macro, 'sugars' => $macro,
            'fat' => $macro, 'saturated_fat' => $macro, 'fibre' => $macro, 'salt' => $macro,
            'ingredients' => ['nullable', 'string'],
            'allergens' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(ProductVerificationStatus::class)],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $service->addVersion($this->product, [
            'serving_basis' => ServingBasis::from($data['serving_basis']),
            'serving_size_value' => $data['serving_size_value'] !== null && $data['serving_size_value'] !== '' ? (float) $data['serving_size_value'] : null,
            'serving_size_unit' => $data['serving_size_unit'] ?: null,
            'calories' => (float) ($data['calories'] ?? 0),
            'protein' => (float) ($data['protein'] ?? 0),
            'carbs' => (float) ($data['carbs'] ?? 0),
            'sugars' => (float) ($data['sugars'] ?? 0),
            'fat' => (float) ($data['fat'] ?? 0),
            'saturated_fat' => (float) ($data['saturated_fat'] ?? 0),
            'fibre' => (float) ($data['fibre'] ?? 0),
            'salt' => (float) ($data['salt'] ?? 0),
            'ingredients' => $data['ingredients'] ?: null,
            'allergens' => $data['allergens'] ?? '',
            'status' => ProductVerificationStatus::from($data['status']),
            'confidence' => (float) $data['confidence'],
        ]);

        $this->reset([
            'serving_size_value', 'calories', 'protein', 'carbs', 'sugars',
            'fat', 'saturated_fat', 'fibre', 'salt', 'ingredients', 'allergens',
        ]);

        $this->dispatch('saved');
    }

    public function with(): array
    {
        return [
            'versions' => $this->product->versions()->with('sources')->latest('effective_from')->latest('id')->get(),
            'basisOptions' => ServingBasis::options(),
            'statusOptions' => ProductVerificationStatus::options(),
        ];
    }
}; ?>

<x-layouts.admin :title="__('Edit product')">
    <div class="mx-auto max-w-2xl space-y-6" x-data="{ saved: false }"
         x-on:saved.window="saved = true; setTimeout(() => saved = false, 2500)">
        <div class="flex items-center gap-2 text-sm text-zinc-500">
            <a href="{{ route('admin.products.index') }}" wire:navigate class="hover:text-zinc-800">Products</a>
            <span>/</span>
            <span class="truncate text-zinc-900">{{ $product->brand }} — {{ $product->name }}</span>
        </div>

        @if (session('status'))
            <div class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ session('status') }}</div>
        @endif

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Edit product</h1>
            <span x-show="saved" x-cloak class="text-sm font-medium text-emerald-600">Saved</span>
        </div>

        {{-- Identity --}}
        <form wire:submit="saveIdentity" class="space-y-4">
            @include('livewire.admin.products._identity-fields')
            <button type="submit" class="rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                Save identity
            </button>
        </form>

        {{-- Existing versions --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-zinc-900">Nutrition versions</h2>
            <div class="mt-3 space-y-3">
                @forelse ($versions as $version)
                    <div class="rounded-xl border border-zinc-100 bg-zinc-50/60 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-zinc-900">
                                {{ $version->serving_basis->label() }}
                                @if ($version->serving_size_value)
                                    <span class="font-normal text-zinc-500">· serving {{ rtrim(rtrim((string) $version->serving_size_value, '0'), '.') }}{{ $version->serving_size_unit }}</span>
                                @endif
                            </p>
                            <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-zinc-600 ring-1 ring-zinc-200">{{ $version->status->label() }}</span>
                        </div>
                        <p class="mt-2 text-xs text-zinc-600">
                            {{ (float) $version->calories }} kcal · P {{ (float) $version->protein }}g · C {{ (float) $version->carbs }}g · F {{ (float) $version->fat }}g · Salt {{ (float) $version->salt }}g
                        </p>
                        <p class="mt-1 text-[11px] text-zinc-400">
                            Effective {{ $version->effective_from?->format('j M Y') }} ·
                            {{ $version->sources->map(fn ($s) => $s->source_type->label())->unique()->implode(', ') ?: 'no source' }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No versions yet — add one below.</p>
                @endforelse
            </div>
        </section>

        {{-- Add a version --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-zinc-900">Add a version</h2>
            <form wire:submit="addVersion" class="mt-4 space-y-4">
                @include('livewire.admin.products._version-fields')
                <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                    Add version
                </button>
            </form>
        </section>
    </div>
</x-layouts.admin>
