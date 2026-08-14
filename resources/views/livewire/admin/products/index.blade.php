<?php

use App\Models\CanonicalProduct;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Admin: browse & search canonical products (BUILD_PLAN §11 Products). Thin —
 * read-only listing; all writes happen in the create/edit forms via
 * CanonicalProductService.
 */
new #[Layout('components.layouts.admin', ['title' => 'Products'])] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $term = trim($this->search);

        $products = CanonicalProduct::query()
            ->withCount('versions')
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.$term.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('brand', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('gtin', 'like', $like);
                });
            })
            ->orderBy('brand')
            ->orderBy('name')
            ->paginate(15);

        return ['products' => $products];
    }
}; ?>

    <div class="space-y-5">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-ink">Products</h1>
                <p class="mt-1 text-sm text-ink-dim">Browse, search and edit canonical products.</p>
            </div>
            <a href="{{ route('admin.products.create') }}"
               class="rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                New product
            </a>
        </div>

        <div>
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Search by brand, name or barcode…"
                   class="w-full rounded-xl border border-seam bg-plate px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        </div>

        <div class="overflow-hidden rounded-2xl border border-seam bg-plate shadow-sm">
            @forelse ($products as $product)
                <a href="{{ route('admin.products.edit', $product) }}"
                   class="flex items-center justify-between gap-4 border-b border-seam px-5 py-4 transition last:border-b-0 hover:bg-plate-well">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-ink">
                            {{ $product->brand }} — {{ $product->name }}
                            @if ($product->variant)
                                <span class="font-normal text-ink-dim">({{ $product->variant }})</span>
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs text-ink-dim">
                            @if ($product->gtin) {{ $product->gtin }} · @endif
                            @if ($product->pack_size_value) {{ rtrim(rtrim((string) $product->pack_size_value, '0'), '.') }}{{ $product->pack_size_unit }} · @endif
                            {{ $product->versions_count }} {{ Str::plural('version', $product->versions_count) }}
                        </p>
                    </div>
                    <svg class="size-4 shrink-0 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            @empty
                <div class="px-5 py-16 text-center text-sm text-ink-dim">
                    {{ trim($search) !== '' ? 'No products match your search.' : 'No products yet. Create the first one.' }}
                </div>
            @endforelse
        </div>

        <div>{{ $products->links() }}</div>
    </div>

