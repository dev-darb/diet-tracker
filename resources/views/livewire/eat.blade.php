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
        </div>

        {{-- The capture flow entry — the ledger records EVERY meal (Reframe, Aug 2026). --}}
        <x-app.console-key primary :href="route('eat.log')">+ Log a meal</x-app.console-key>

        @if ($groups->isEmpty())
            <x-app.placeholder
                status="NO ENTRIES"
                title="Nothing logged yet"
                subtitle="Log a meal, or tap Eat on a pantry item." />
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
                            {{-- The row is the record; touching it opens the record's
                                 detail + actions. Corrective controls (edit/delete) are
                                 occasional acts and live inside, not on every row
                                 (clutter critique, Aug 2026). --}}
                            <li x-data="{ inspect: false }">
                                <button type="button" x-on:click="inspect = !inspect"
                                        class="flex min-h-[44px] w-full items-center gap-2.5 px-4 py-2.5 text-left transition hover:bg-plate-raised">
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
                                    <svg class="size-3.5 shrink-0 text-ink-faint transition-transform" :class="inspect && 'rotate-180'"
                                         fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </button>

                                {{-- The opened record: component breakdown (when there is
                                     one) + the corrective actions. SPLIT reveal. --}}
                                <div class="split" :class="inspect && 'split-open'">
                                <div>
                                <div class="border-t border-seam bg-plate-well px-5 py-3">
                                    @if ($event->items->isNotEmpty())
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
                                    @endif

                                    <div class="flex items-center gap-2 {{ $event->items->isNotEmpty() ? 'mt-2 border-t border-seam pt-2.5' : '' }}">
                                        {{-- Amount edit is for single items only; meals delete + re-log (per-line editing is M5). --}}
                                        @if ($event->type !== \App\Enums\ConsumptionType::Meal)
                                            <button type="button" wire:click="startEdit({{ $event->id }})"
                                                    class="key keycap-sm hit px-3 py-1.5 text-ink-dim">Edit</button>
                                        @endif
                                        <button type="button" wire:click="deleteEntry({{ $event->id }})" wire:loading.attr="disabled"
                                                class="keycap-sm hit px-2 py-1.5 text-ink-faint transition hover:text-high">Delete</button>
                                    </div>
                                </div>
                                </div>
                                </div>

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
            <div class="stamp-in flex items-center justify-between gap-3 rounded-md border border-seam-strong bg-chassis px-4 py-3 text-ink">
                <span class="keycap">Entry deleted</span>
                <button type="button"
                        x-on:click="del = false; clearTimeout(delT); $wire.cancelDelete()"
                        class="keycap hit shrink-0 text-action">
                    Undo
                </button>
            </div>
        </div>
    </div>
