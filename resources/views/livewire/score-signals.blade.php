<?php

use App\Models\AiInsight;
use App\Services\FoodyScore\FoodyScoreService;
use App\Services\FoodyScore\ScoreInsightService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Home's dynamic signals (Foody Score spec §14): at most three worded
 * insights, normally one or two, nothing at all when the day is quiet.
 *
 * THIN and NEVER blocking (product principle 7): the score headline above
 * renders instantly from the deterministic record; this island loads via
 * wire:init, so the first wording of the day (the only step that may touch a
 * model) happens while the page is already usable. Dismissal is per-signal
 * with an immediate undo.
 */
new class extends Component
{
    /** Set by wire:init — with() makes no service call until then. */
    public bool $loaded = false;

    /** The last dismissed row, so the strip can offer Undo. */
    public ?int $dismissedId = null;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function dismiss(int $id): void
    {
        $insight = AiInsight::query()->whereKey($id)->where('user_id', Auth::id())->first();

        if ($insight !== null) {
            $insight->forceFill(['dismissed_at' => now()])->save();
            $this->dismissedId = $insight->id;
        }
    }

    public function undoDismiss(): void
    {
        $insight = AiInsight::query()->whereKey($this->dismissedId)->where('user_id', Auth::id())->first();

        if ($insight !== null) {
            $insight->forceFill(['dismissed_at' => null])->save();
        }

        $this->dismissedId = null;
    }

    public function with(FoodyScoreService $scores, ScoreInsightService $signals): array
    {
        if (! $this->loaded) {
            return ['signals' => collect()];
        }

        $user = Auth::user();
        $record = $scores->latest($user);

        // Home's headline computed today's record just before this island
        // loaded; recompute only if that assumption ever fails.
        if ($record === null || $record->score_date->toDateString() !== now()->toDateString()) {
            $record = $scores->computeAndRecord($user);
        }

        return ['signals' => $signals->insightsFor($user, $record)];
    }
}; ?>

<div wire:init="load">
    <div wire:loading.delay wire:target="load">
        <div class="module flex items-center gap-3 px-5 py-3.5">
            <span class="size-2 shrink-0 animate-pulse rounded-full bg-info" aria-hidden="true"></span>
            <span class="silkscreen">Signals</span>
        </div>
    </div>

    <div wire:loading.remove wire:target="load" class="space-y-3">
        @foreach ($signals as $signal)
            <div class="module px-5 pb-4 pt-4" wire:key="signal-{{ $signal->id }}">
                <div class="flex items-center justify-between">
                    <h2 class="silkscreen">Signal</h2>
                    <button type="button" wire:click="dismiss({{ $signal->id }})" wire:loading.attr="disabled"
                            class="keycap-sm hit px-2 py-1 text-ink-faint transition hover:text-ink-dim"
                            aria-label="Dismiss this signal">
                        Dismiss
                    </button>
                </div>
                <h3 class="voice-item mt-2.5 text-ink">{{ $signal->title }}</h3>
                <p class="voice-body mt-1 text-ink-dim">{{ $signal->body }}</p>
            </div>
        @endforeach

        @if ($dismissedId !== null)
            <div class="module flex items-center justify-between gap-3 px-5 py-3">
                <span class="voice-caption text-ink-dim">Signal dismissed for today.</span>
                <button type="button" wire:click="undoDismiss" wire:loading.attr="disabled"
                        class="keycap-sm hit shrink-0 text-ink transition hover:text-action">
                    Undo
                </button>
            </div>
        @endif
    </div>
</div>
