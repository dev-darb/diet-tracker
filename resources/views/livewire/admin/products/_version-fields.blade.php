{{-- Shared nutrition-version fields for the admin create form and the
     "add version" form on edit. Bound to properties present on both. --}}
<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="text-xs font-medium text-zinc-600">Serving basis</label>
            <select wire:model.live="serving_basis"
                    class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                @foreach ($basisOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            @error('serving_basis') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Serving size</label>
            <div class="mt-1 flex gap-2">
                <input type="number" step="any" min="0" inputmode="decimal" wire:model="serving_size_value" placeholder="e.g. 30"
                       class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <select wire:model="serving_size_unit" class="rounded-lg border border-zinc-200 px-2 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="g">g</option>
                    <option value="ml">ml</option>
                </select>
            </div>
            @error('serving_size_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <p class="text-xs font-medium text-zinc-600">Macros <span class="font-normal text-zinc-400">(per the chosen basis)</span></p>
        <div class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                'calories' => 'Calories (kcal)',
                'protein' => 'Protein (g)',
                'carbs' => 'Carbs (g)',
                'sugars' => 'Sugars (g)',
                'fat' => 'Fat (g)',
                'saturated_fat' => 'Saturated fat (g)',
                'fibre' => 'Fibre (g)',
                'salt' => 'Salt (g)',
            ] as $field => $label)
                <div>
                    <label class="text-[11px] text-zinc-500">{{ $label }}</label>
                    <input type="number" step="any" min="0" inputmode="decimal" wire:model="{{ $field }}"
                           class="mt-1 w-full rounded-lg border border-zinc-200 px-2.5 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error($field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>
    </div>

    <div>
        <label class="text-xs font-medium text-zinc-600">Ingredients</label>
        <textarea wire:model="ingredients" rows="2"
                  class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="text-xs font-medium text-zinc-600">Allergens <span class="font-normal text-zinc-400">(comma separated)</span></label>
            <input type="text" wire:model="allergens" placeholder="e.g. milk, soy"
                   class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        </div>
        <div>
            <label class="text-xs font-medium text-zinc-600">Status</label>
            <select wire:model="status"
                    class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                @foreach ($statusOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="w-40">
        <label class="text-xs font-medium text-zinc-600">Confidence (0–1)</label>
        <input type="number" step="0.01" min="0" max="1" wire:model="confidence"
               class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        @error('confidence') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
