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
new #[Layout('components.layouts.app', ['title' => 'Eat'])] class extends Component
{
    public ?int $editingId = null;

    public string $editQuantity = '';

    public string $editTime = '';

    /**
     * Delete is an undoable automatic action, not a confirmation dialog: the
     * entry is marked here (and vanishes from the log), the toast offers Undo,
     * and only after the undo window closes does the service really delete.
     */
    public ?int $pendingDeleteId = null;

    public function startEdit(int $id): void
    {
        $event = $this->ownedEvent($id);
        $line = $event->items()->first();

        $this->editingId = $id;
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
        // A second delete while one is pending commits the first immediately.
        $this->commitDelete($service);

        $this->ownedEvent($id); // authorisation check before we claim "deleted"
        $this->pendingDeleteId = $id;

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
        $this->dispatch('consumption-deleted');
    }

    /** The undo window closed — make the deletion real (pantry is restored). */
    public function commitDelete(ConsumptionService $service): void
    {
        if ($this->pendingDeleteId === null) {
            return;
        }

        $event = Auth::user()->consumptionEvents()->find($this->pendingDeleteId);

        if ($event !== null) {
            $service->deleteConsumption($event);
        }

        $this->pendingDeleteId = null;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
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
            ->get()
            // An entry pending deletion is already gone from the user's view;
            // the toast's Undo is its only way back.
            ->reject(fn (ConsumptionEvent $e) => $e->id === $this->pendingDeleteId);

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

    <div class="space-y-3" x-data="{ toast: false, del: false, delT: null }"
         x-on:consumption-updated.window="toast = true; setTimeout(() => toast = false, 2000)"
         x-on:consumption-deleted.window="del = true; clearTimeout(delT); delT = setTimeout(() => { del = false; $wire.commitDelete(); }, 6000)">
        <div class="px-1">
            <h1 class="voice-title text-ink">Eat</h1>
            <p class="voice-caption mt-0.5 text-ink-dim">What you've logged. Consume items from your <a href="{{ route('pantry') }}" wire:navigate class="text-ink underline decoration-seam-strong underline-offset-4 transition hover:decoration-action">pantry</a>, or log any meal.</p>
        </div>

        {{-- The capture flow entry — the ledger records EVERY meal (Reframe, Aug 2026). --}}
        <x-app.console-key primary :href="route('eat.log')">+ Log a meal</x-app.console-key>

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
                        <span class="data-sm {{ $group['label'] === 'Today' ? 'text-ink' : 'text-ink-faint' }}">
                            {{ $group['calories'] === null ? '----' : number_format($group['calories']).' KCAL' }}
                        </span>
                    </div>

                    <ul class="mt-2 divide-y divide-seam">
                        @foreach ($group['events'] as $event)
                            @php($line = $event->items->first())
                            <li x-data="{ inspect: false }">
                                <div class="flex min-h-[44px] items-center gap-2.5 px-4 py-2.5">
                                    <span class="data-sm w-10 shrink-0 text-ink-faint">{{ $event->consumed_at->format('H:i') }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="data-md leading-snug text-ink uppercase">{{ $event->name ?: 'Consumption' }}</p>
                                        {{-- Echo the user's own portion language when we have it
                                             ("half the pack (200 g)"); meals describe their source.
                                             Every row leads with its context glyph (icon rule: whole list or none). --}}
                                        <p class="data-sm mt-0.5 flex items-center gap-1.5 text-ink-faint">
                                            <x-app.icon :name="match (true) {
                                                $event->context === \App\Enums\MealContext::EatingOut => 'storefront',
                                                $event->type === \App\Enums\ConsumptionType::Meal => 'pan',
                                                default => 'basket',
                                            }" class="size-3.5 shrink-0 text-ink-faint" />
                                            <span class="min-w-0 truncate">
                                            @if ($event->context === \App\Enums\MealContext::EatingOut)
                                                EATING OUT{{ $event->venue ? ' · '.mb_strtoupper($event->venue) : '' }}{{ $event->estimated ? ' · ESTIMATED' : '' }}
                                            @elseif ($event->type === \App\Enums\ConsumptionType::Meal)
                                                HOME-COOKED · {{ $event->items->count() }} {{ \Illuminate\Support\Str::plural('COMPONENT', $event->items->count()) }}
                                            @elseif ($line?->portion_label)
                                                {{ $line->portion_label }}
                                            @else
                                                {{ rtrim(rtrim(number_format((float) ($line->quantity ?? 0), 3, '.', ''), '0'), '.') }}
                                                <span class="uppercase">{{ $line?->unit?->shortLabelFor((float) ($line->quantity ?? 0)) ?? '' }}</span>
                                            @endif
                                            </span>
                                        </p>
                                    </div>
                                    {{-- Estimated figures wear their tilde honestly (brief §2.1). --}}
                                    <span class="data-md shrink-0 whitespace-nowrap text-ink">
                                        {{ $event->calories === null ? '----' : ($event->estimated ? '~' : '').number_format((float) $event->calories, 0) }}
                                    </span>
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        @if ($event->items->isNotEmpty())
                                        <button type="button" x-on:click="inspect = !inspect" title="Details" aria-label="Details"
                                                class="hit flex size-8 items-center justify-center text-ink-faint transition hover:text-ink">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                                        </button>
                                        @endif
                                        {{-- Amount edit is for single items only; meals delete + re-log (per-line editing is M5). --}}
                                        @if ($event->type !== \App\Enums\ConsumptionType::Meal)
                                        <button type="button" wire:click="startEdit({{ $event->id }})" title="Edit" aria-label="Edit"
                                                class="hit flex size-8 items-center justify-center text-ink-faint transition hover:text-ink">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                        </button>
                                        @endif
                                        <button type="button" wire:click="deleteEntry({{ $event->id }})" wire:loading.attr="disabled" title="Delete" aria-label="Delete"
                                                class="hit flex size-8 items-center justify-center text-ink-faint transition hover:text-high">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Inspect components — pure disclosure, so pure client state. --}}
                                @if ($event->items->isNotEmpty())
                                    <div x-show="inspect" x-cloak class="border-t border-seam bg-plate-well px-5 py-3">
                                        @foreach ($event->items as $component)
                                            <div class="flex items-center justify-between gap-3 py-1 text-xs">
                                                <span class="voice-micro min-w-0 truncate text-ink-dim">{{ $component->canonicalProduct?->name ?? 'Item' }}
                                                    <span class="data-sm text-ink-faint">· @if ($component->portion_label){{ $component->portion_label }}@else{{ rtrim(rtrim(number_format((float) $component->quantity, 3, '.', ''), '0'), '.') }} <span class="uppercase">{{ $component->unit->shortLabel() }}</span>@endif</span>
                                                </span>
                                                <span class="data-sm shrink-0 text-ink-dim">
                                                    {{ $component->protein === null ? '--' : number_format((float) $component->protein, 1) }}P ·
                                                    {{ $component->carbs === null ? '--' : number_format((float) $component->carbs, 1) }}C ·
                                                    {{ $component->fat === null ? '--' : number_format((float) $component->fat, 1) }}F
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
                                                       class="input-well data mt-1.5 !bg-plate">
                                            </div>
                                            <div class="min-w-[10rem] flex-1">
                                                <label class="silkscreen" for="edit-time-{{ $event->id }}">Time</label>
                                                <input id="edit-time-{{ $event->id }}" type="datetime-local" wire:model="editTime"
                                                       class="input-well data mt-1.5 !bg-plate">
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="saveEdit" wire:loading.attr="disabled"
                                                        class="key key-action keycap px-4 py-2.5">Save</button>
                                                <button type="button" wire:click="cancelEdit" class="keycap-sm text-ink-dim transition hover:text-ink">Cancel</button>
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

        <x-app.stamp-toast show="toast">Updated</x-app.stamp-toast>

        {{-- Deletion's undo window: the entry is out of the log, the pantry will
             be restored when the window closes — one tap brings it straight back. --}}
        <div x-show="del" x-cloak role="status" class="fixed inset-x-0 bottom-28 z-40 mx-auto max-w-md px-5">
            <div class="stamp-in flex items-center justify-between gap-3 rounded-md border border-seam bg-plate-raised px-4 py-3 text-ink">
                <span class="keycap">Entry deleted</span>
                <button type="button"
                        x-on:click="del = false; clearTimeout(delT); $wire.cancelDelete()"
                        class="keycap hit shrink-0 text-action">
                    Undo
                </button>
            </div>
        </div>
    </div>
