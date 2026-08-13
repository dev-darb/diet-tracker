{{-- Shared canonical-identity fields for the admin create/edit forms. Bound to
     properties present on both components. --}}
<section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
    <h2 class="text-sm font-semibold text-zinc-900">Identity</h2>
    <div class="mt-4 grid grid-cols-2 gap-4">
        <div>
            <label class="text-xs font-medium text-zinc-600">Brand</label>
            <input type="text" wire:model="brand"
                   class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('brand') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Name</label>
            <input type="text" wire:model="name"
                   class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Variant</label>
            <input type="text" wire:model="variant" placeholder="e.g. Original"
                   class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('variant') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Barcode (GTIN)</label>
            <input type="text" wire:model="gtin" placeholder="Optional"
                   class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('gtin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Pack size</label>
            <div class="mt-1 flex gap-2">
                <input type="number" step="any" min="0" inputmode="decimal" wire:model="pack_size_value" placeholder="e.g. 500"
                       class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <select wire:model="pack_size_unit" class="rounded-lg border border-zinc-200 px-2 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="g">g</option>
                    <option value="ml">ml</option>
                </select>
            </div>
            @error('pack_size_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Category</label>
            <input type="text" wire:model="category" placeholder="e.g. snacks"
                   class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</section>
