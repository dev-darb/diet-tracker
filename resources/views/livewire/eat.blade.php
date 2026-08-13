<?php

use App\Models\ConsumptionEvent;
use App\Services\ConsumptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Eat — recent consumption history (BUILD_PLAN §6 J4.2; brief §8.6). Groups the
 * user's logged eating into days (Today first) and shows time / name / kcal per
 * entry. Edit (quantity + time), delete and inspect-components all route through
 * ConsumptionService so snapshots + the pantry ledger stay consistent — this
 * component never mutates consumption or ledger rows directly.
 */
new class extends Component {
    public ?int $editingId = null;
    public string $editQuantity = '';
    public string $editTime = '';

    public ?int $inspectingId = null;

    public function startEdit(int $id): void
    {
        $event = $this->ownedEvent($id);
        $line = $event->items()->first();

        $this->editingId = $id;
        $this->inspectingId = null;
        $this->editQuantity = $line
            ? rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.')
            : '1';
        $this->editTime = $event->consumed_at->format('Y-m-d\TH:i');
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editQuantity', 'editTime']);
        $this->resetValidation();
    }

    public function saveEdit(ConsumptionService $service): void
    {
        $data = $this->validate([
            'editQuantity' => ['required', 'numeric', 'gt:0'],
            'editTime' => ['required', 'date'],
        ]);

        $service->editConsumption(
            $this->ownedEvent($this->editingId),
            (float) $data['editQuantity'],
            null,
            Carbon::parse($data['editTime']),
        );

        $this->cancelEdit();
        $this->dispatch('consumption-updated');
    }

    public function deleteEntry(int $id, ConsumptionService $service): void
    {
        $service->deleteConsumption($this->ownedEvent($id));

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
        $this->dispatch('consumption-updated');
    }

    public function toggleInspect(int $id): void
    {
        $this->inspectingId = $this->inspectingId === $id ? null : $id;
    }

    private function ownedEvent(int $id): ConsumptionEvent
    {
        return Auth::user()->consumptionEvents()->findOrFail($id);
    }

    public function with(): array
    {
        $events = Auth::user()->consumptionEvents()
            ->with('items.canonicalProduct')
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $groups = $events
            ->groupBy(fn (ConsumptionEvent $e) => $e->consumed_at->toDateString())
            ->map(fn ($dayEvents, $date) => [
                'label' => $this->dayLabel(Carbon::parse($date)),
                'events' => $dayEvents,
                'calories' => $dayEvents->contains(fn ($e) => $e->calories === null)
                    ? null
                    : round((float) $dayEvents->sum(fn ($e) => (float) $e->calories), 0),
            ])
            ->values();

        return ['groups' => $groups];
    }

    private function dayLabel(Carbon $date): string
    {
        return match (true) {
            $date->isToday() => 'Today',
            $date->isYesterday() => 'Yesterday',
            default => $date->format('D j M'),
        };
    }
}; ?>

<x-layouts.app :title="__('Eat')">
    <div class="space-y-5" x-data="{ toast: false }"
         x-on:consumption-updated.window="toast = true; setTimeout(() => toast = false, 2000)">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">Eat</h1>
            <p class="mt-1 text-sm text-zinc-500">What you've logged. Consume items from your <a href="{{ route('pantry') }}" wire:navigate class="font-medium text-emerald-700 hover:text-emerald-800">pantry</a>.</p>
        </div>

        @if ($groups->isEmpty())
            <x-app.placeholder
                title="Nothing logged yet"
                subtitle="Open a pantry item and tap “I ate one”. It'll show up here grouped by day, with what it added to your intake.">
                <x-slot:icon>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v6.75m0 0a2.25 2.25 0 002.25-2.25V3m-4.5 0v4.5A2.25 2.25 0 008.25 9.75m0 0V21m7.5-18v18m0-18c1.243 0 2.25 1.79 2.25 4v5.25c0 .414-.336.75-.75.75H15.75" />
                    </svg>
                </x-slot:icon>
            </x-app.placeholder>
        @else
            @foreach ($groups as $group)
                <section class="space-y-2">
                    <div class="flex items-baseline justify-between px-1">
                        <h2 class="text-sm font-semibold text-zinc-900">{{ $group['label'] }}</h2>
                        <span class="text-xs font-medium text-zinc-500">
                            {{ $group['calories'] === null ? '—' : number_format($group['calories']) }} kcal
                        </span>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm">
                        @foreach ($group['events'] as $event)
                            @php($line = $event->items->first())
                            <div class="border-b border-zinc-100 last:border-b-0">
                                <div class="flex items-center gap-4 px-5 py-3.5">
                                    <span class="w-12 shrink-0 text-xs font-medium tabular-nums text-zinc-400">{{ $event->consumed_at->format('H:i') }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-zinc-900">{{ $event->name ?: 'Consumption' }}</p>
                                        <p class="mt-0.5 text-xs text-zinc-500">
                                            {{ rtrim(rtrim(number_format((float) ($line->quantity ?? 0), 3, '.', ''), '0'), '.') }}
                                            {{ $line?->unit?->shortLabel() }}
                                        </p>
                                    </div>
                                    <span class="whitespace-nowrap text-sm font-semibold tabular-nums text-zinc-900">
                                        {{ $event->calories === null ? '—' : number_format((float) $event->calories, 0) }}
                                        <span class="text-xs font-normal text-zinc-500">kcal</span>
                                    </span>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <button type="button" wire:click="toggleInspect({{ $event->id }})" title="Details"
                                                class="text-zinc-400 transition hover:text-zinc-700">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                                        </button>
                                        <button type="button" wire:click="startEdit({{ $event->id }})" title="Edit"
                                                class="text-zinc-400 transition hover:text-zinc-700">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                        </button>
                                        <button type="button" wire:click="deleteEntry({{ $event->id }})" wire:confirm="Delete this entry? Your pantry will be restored." title="Delete"
                                                class="text-zinc-400 transition hover:text-red-600">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Inspect components (single items show the product; meals arrive in M5) --}}
                                @if ($inspectingId === $event->id)
                                    <div class="border-t border-zinc-100 bg-zinc-50/60 px-5 py-3">
                                        @foreach ($event->items as $component)
                                            <div class="flex items-center justify-between py-1 text-xs">
                                                <span class="text-zinc-600">{{ $component->canonicalProduct?->name ?? 'Item' }}
                                                    <span class="text-zinc-400">· {{ rtrim(rtrim(number_format((float) $component->quantity, 3, '.', ''), '0'), '.') }} {{ $component->unit->shortLabel() }}</span>
                                                </span>
                                                <span class="tabular-nums text-zinc-500">
                                                    P {{ $component->protein === null ? '—' : number_format((float) $component->protein, 1) }} ·
                                                    C {{ $component->carbs === null ? '—' : number_format((float) $component->carbs, 1) }} ·
                                                    F {{ $component->fat === null ? '—' : number_format((float) $component->fat, 1) }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Edit quantity + time --}}
                                @if ($editingId === $event->id)
                                    <div class="border-t border-zinc-100 bg-zinc-50/60 px-5 py-4">
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div class="w-24">
                                                <label class="text-xs font-medium text-zinc-600">Amount</label>
                                                <input type="number" step="any" min="0" inputmode="decimal" wire:model="editQuantity"
                                                       class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                            </div>
                                            <div class="flex-1 min-w-[10rem]">
                                                <label class="text-xs font-medium text-zinc-600">Time</label>
                                                <input type="datetime-local" wire:model="editTime"
                                                       class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="saveEdit"
                                                        class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">Save</button>
                                                <button type="button" wire:click="cancelEdit" class="text-sm font-medium text-zinc-500 hover:text-zinc-800">Cancel</button>
                                            </div>
                                        </div>
                                        @error('editQuantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                        @error('editTime') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif

        <div x-show="toast" x-cloak class="fixed inset-x-0 bottom-24 z-40 mx-auto max-w-md px-5">
            <div class="rounded-xl bg-zinc-900 px-4 py-2.5 text-center text-sm font-medium text-white shadow-lg">Updated</div>
        </div>
    </div>
</x-layouts.app>
