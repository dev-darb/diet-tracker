<?php

use App\Models\ConsumptionEvent;
use App\Services\ConsumptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Eat — recent consumption history (BUILD_PLAN §6 J4.2; brief §8.6). Groups the
 * user's logged eating into days (Today first) and shows time / name / kcal per
 * entry. Edit (quantity + time), delete and inspect-components all route through
 * ConsumptionService so snapshots + the pantry ledger stay consistent — this
 * component never mutates consumption or ledger rows directly.
 */
new #[Layout('components.layouts.app', ['title' => 'Eat'])] class extends Component {
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

    <div class="space-y-3" x-data="{ toast: false }"
         x-on:consumption-updated.window="toast = true; setTimeout(() => toast = false, 2000)">
        <div class="px-1">
            <h1 class="text-xl font-medium tracking-tight text-ink">Eat</h1>
            <p class="mt-0.5 text-sm text-ink-dim">What you've logged. Consume items from your <a href="{{ route('pantry') }}" class="text-ink underline decoration-seam-strong underline-offset-4 transition hover:decoration-action">pantry</a>.</p>
        </div>

        @if ($groups->isEmpty())
            <x-app.placeholder
                status="NO ENTRIES"
                title="Nothing logged yet"
                subtitle="Open a pantry item and tap &ldquo;I ate one&rdquo;. It'll show up here as today's log, with what it added to your intake." />
        @else
            {{-- The day log: a terminal feed, newest day first (comp C grammar). --}}
            @foreach ($groups as $group)
                <section class="module px-0 pb-1 pt-4">
                    <div class="flex items-baseline justify-between px-5">
                        <h2 class="silkscreen">{{ $group['label'] }}</h2>
                        <span class="data text-[11px] text-ink-dim">
                            {{ $group['calories'] === null ? '----' : number_format($group['calories']).' KCAL' }}
                        </span>
                    </div>

                    <ul class="mt-2 divide-y divide-seam">
                        @foreach ($group['events'] as $event)
                            @php($line = $event->items->first())
                            <li>
                                <div class="flex min-h-[44px] items-center gap-2.5 px-4 py-2.5">
                                    <span class="data w-10 shrink-0 text-[11px] text-ink-faint">{{ $event->consumed_at->format('H:i') }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="data text-sm leading-snug text-ink uppercase">{{ $event->name ?: 'Consumption' }}</p>
                                        <p class="data mt-0.5 text-[11px] text-ink-faint">
                                            {{ rtrim(rtrim(number_format((float) ($line->quantity ?? 0), 3, '.', ''), '0'), '.') }}
                                            <span class="uppercase">{{ $line?->unit?->shortLabelFor((float) ($line->quantity ?? 0)) ?? '' }}</span>
                                        </p>
                                    </div>
                                    <span class="data shrink-0 whitespace-nowrap text-sm text-ink">
                                        {{ $event->calories === null ? '----' : number_format((float) $event->calories, 0) }}
                                    </span>
                                    <div class="flex shrink-0 items-center">
                                        <button type="button" wire:click="toggleInspect({{ $event->id }})" title="Details" aria-label="Details"
                                                class="flex size-7 items-center justify-center text-ink-faint transition hover:text-ink">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                                        </button>
                                        <button type="button" wire:click="startEdit({{ $event->id }})" title="Edit" aria-label="Edit"
                                                class="flex size-7 items-center justify-center text-ink-faint transition hover:text-ink">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                        </button>
                                        <button type="button" wire:click="deleteEntry({{ $event->id }})" wire:confirm="Delete this entry? Your pantry will be restored." title="Delete" aria-label="Delete"
                                                class="flex size-7 items-center justify-center text-ink-faint transition hover:text-high">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Inspect components (single items show the product; meals arrive in M5) --}}
                                @if ($inspectingId === $event->id)
                                    <div class="border-t border-seam bg-plate-well px-5 py-3">
                                        @foreach ($event->items as $component)
                                            <div class="flex items-center justify-between gap-3 py-1 text-xs">
                                                <span class="min-w-0 truncate text-ink-dim">{{ $component->canonicalProduct?->name ?? 'Item' }}
                                                    <span class="data text-ink-faint">· {{ rtrim(rtrim(number_format((float) $component->quantity, 3, '.', ''), '0'), '.') }} <span class="uppercase">{{ $component->unit->shortLabel() }}</span></span>
                                                </span>
                                                <span class="data shrink-0 text-ink-dim">
                                                    P {{ $component->protein === null ? '--' : number_format((float) $component->protein, 1) }} ·
                                                    C {{ $component->carbs === null ? '--' : number_format((float) $component->carbs, 1) }} ·
                                                    F {{ $component->fat === null ? '--' : number_format((float) $component->fat, 1) }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Edit quantity + time --}}
                                @if ($editingId === $event->id)
                                    <div class="border-t border-seam bg-plate-well px-5 py-4">
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div class="w-24">
                                                <label class="silkscreen" for="edit-qty-{{ $event->id }}">Amount</label>
                                                <input id="edit-qty-{{ $event->id }}" type="number" step="any" min="0" inputmode="decimal" wire:model="editQuantity"
                                                       class="data mt-1.5 w-full rounded-[5px] border border-seam bg-plate px-3 py-2 text-sm text-ink focus:border-action focus:outline-none">
                                            </div>
                                            <div class="min-w-[10rem] flex-1">
                                                <label class="silkscreen" for="edit-time-{{ $event->id }}">Time</label>
                                                <input id="edit-time-{{ $event->id }}" type="datetime-local" wire:model="editTime"
                                                       class="data mt-1.5 w-full rounded-[5px] border border-seam bg-plate px-3 py-2 text-sm text-ink [color-scheme:dark] focus:border-action focus:outline-none">
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="saveEdit"
                                                        class="key key-action px-4 py-2.5 font-mono text-[13px] tracking-[0.1em] uppercase">Save</button>
                                                <button type="button" wire:click="cancelEdit" class="font-mono text-[11px] tracking-[0.08em] text-ink-dim uppercase transition hover:text-ink">Cancel</button>
                                            </div>
                                        </div>
                                        @error('editQuantity') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                                        @error('editTime') <p class="mt-1 text-xs text-high">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif

        <div x-show="toast" x-cloak class="fixed inset-x-0 bottom-28 z-40 mx-auto max-w-md px-5">
            <div class="stamp-in flex items-center justify-center gap-2.5 rounded-md bg-good px-4 py-3 text-black">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5.5 5.5L20 6.5" /></svg>
                <span class="font-mono text-sm tracking-[0.14em] uppercase">Updated</span>
            </div>
        </div>
    </div>
