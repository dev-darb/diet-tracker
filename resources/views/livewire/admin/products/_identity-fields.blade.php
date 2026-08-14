{{-- Shared canonical-identity fields for the admin create/edit forms. Bound to
     properties present on both components. --}}
<section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
    <h2 class="text-sm font-semibold text-ink">Identity</h2>
    <div class="mt-4 grid grid-cols-2 gap-4">
        <div>
            <label class="text-xs font-medium text-ink-dim">Brand</label>
            <input type="text" wire:model="brand"
                   class="mt-1 w-full rounded-lg border border-seam px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('brand') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-ink-dim">Name</label>
            <input type="text" wire:model="name"
                   class="mt-1 w-full rounded-lg border border-seam px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-ink-dim">Variant</label>
            <input type="text" wire:model="variant" placeholder="e.g. Original"
                   class="mt-1 w-full rounded-lg border border-seam px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('variant') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-ink-dim">Barcode (GTIN)</label>
            <input type="text" wire:model="gtin" placeholder="Optional"
                   class="mt-1 w-full rounded-lg border border-seam px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('gtin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-ink-dim">Pack size</label>
            <div class="mt-1 flex gap-2">
                <input type="number" step="any" min="0" inputmode="decimal" wire:model="pack_size_value" placeholder="e.g. 500"
                       class="w-full rounded-lg border border-seam px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <select wire:model="pack_size_unit" class="rounded-lg border border-seam px-2 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="g">g</option>
                    <option value="ml">ml</option>
                </select>
            </div>
            @error('pack_size_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-ink-dim">Category</label>
            <input type="text" wire:model="category" placeholder="e.g. snacks"
                   class="mt-1 w-full rounded-lg border border-seam px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</section>
