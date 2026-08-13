<?php

use App\Services\NutritionAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Home / Today (BUILD_PLAN §6 J6.2; brief §9.3). A calm consumer-first snapshot:
 * today's calories, a couple of key macros, and the qualitative component
 * indicators. All figures come from NutritionAnalyticsService — this component
 * does NO arithmetic (brief §9.9).
 */
new class extends Component
{
    public function with(NutritionAnalyticsService $analytics): array
    {
        return ['today' => $analytics->dailySummary(Auth::user())];
    }
}; ?>

<x-layouts.app :title="__('Home')">
    <div class="space-y-6">
        <div>
            <p class="text-sm text-zinc-500">{{ now()->format('l, j F') }}</p>
            <h1 class="mt-0.5 text-2xl font-semibold tracking-tight text-zinc-900">
                Hi {{ str(auth()->user()->name)->before(' ') }}
            </h1>
        </div>

        {{-- Today snapshot (brief §9.3): kcal + key macros, calm not bodybuilding-first. --}}
        <div class="rounded-2xl bg-zinc-900 px-5 py-6 text-white">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Today</p>
            @if ($today['has_data'])
                <p class="mt-1 text-3xl font-semibold tabular-nums">
                    {{ $today['totals']['calories'] === null ? '—' : number_format((float) $today['totals']['calories']) }}
                    <span class="text-base font-normal text-zinc-400">kcal</span>
                </p>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    @foreach (['protein' => 'Protein', 'carbs' => 'Carbs', 'fat' => 'Fat'] as $key => $label)
                        <div class="rounded-xl bg-white/5 px-3 py-2.5">
                            <p class="text-[11px] uppercase tracking-wide text-zinc-400">{{ $label }}</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums">
                                {{ $today['totals'][$key] === null ? '—' : rtrim(rtrim(number_format((float) $today['totals'][$key], 1), '0'), '.') }}<span class="text-xs font-normal text-zinc-400">g</span>
                            </p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-1 text-3xl font-semibold">—</p>
                <p class="mt-1 text-sm text-zinc-400">Your daily snapshot will appear here once you log what you eat today.</p>
            @endif
        </div>

        {{-- Your focus this week — the top AI (or deterministic) insight (brief §9.6). --}}
        <livewire:insight-card />

        @if ($today['has_data'])
            {{-- Component indicators (brief §9.5): Good / OK / Low / Slightly high. --}}
            <div class="space-y-2">
                <div class="flex items-baseline justify-between px-1">
                    <h2 class="text-sm font-semibold text-zinc-900">How today looks</h2>
                    <a href="{{ route('health') }}" wire:navigate class="text-xs font-medium text-emerald-700 hover:text-emerald-800">This week →</a>
                </div>
                <x-app.indicators :indicators="$today['indicators']" />
                <x-app.health-disclaimer />
            </div>
        @endif

        {{-- Primary shortcut: scan --}}
        <a href="{{ route('scan') }}" wire:navigate
           class="flex items-center justify-between rounded-2xl bg-emerald-600 px-5 py-4 text-white shadow-sm transition hover:bg-emerald-700">
            <div>
                <p class="text-base font-semibold">Scan a product</p>
                <p class="text-sm text-emerald-100">Add what you bought to your pantry.</p>
            </div>
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
        </a>

        <div class="grid grid-cols-2 gap-3">
            <a href="{{ route('pantry') }}" wire:navigate class="rounded-2xl border border-zinc-100 bg-white px-4 py-4 shadow-sm transition hover:border-zinc-200">
                <p class="text-sm font-semibold text-zinc-900">Pantry</p>
                <p class="mt-0.5 text-xs text-zinc-500">What you have</p>
            </a>
            <a href="{{ route('health') }}" wire:navigate class="rounded-2xl border border-zinc-100 bg-white px-4 py-4 shadow-sm transition hover:border-zinc-200">
                <p class="text-sm font-semibold text-zinc-900">Health</p>
                <p class="mt-0.5 text-xs text-zinc-500">Your trends</p>
            </a>
        </div>
    </div>
</x-layouts.app>
