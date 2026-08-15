<?php

use App\Enums\ActivityLevel;
use App\Enums\DietaryPattern;
use App\Enums\PrimaryGoal;
use App\Enums\Sex;
use App\Services\ProfileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Lightweight 4-step onboarding (brief §6.3/§6.4).
 *
 * Only the primary goal is required. Steps 2 and 3 are entirely optional and
 * use progressive-disclosure copy so setup never feels like a medical form.
 * All persistence is delegated to ProfileService (thin-component rule).
 */
new #[Layout('components.layouts.focus')] class extends Component {
    public int $step = 1;

    public const LAST_STEP = 4;

    // Step 1 — required
    public string $primary_goal = '';

    // Step 2 — optional preferences & allergies
    public string $dietary_pattern = '';
    /** @var array<int, string> */
    public array $dietary_preferences = [];
    /** @var array<int, string> */
    public array $allergies = [];
    public string $avoided_foods = '';

    // Step 3 — optional body/profile info
    public string $date_of_birth = '';
    public string $sex = '';
    public ?string $height_cm = null;
    public ?string $weight_kg = null;
    public string $activity_level = '';

    public array $preferenceOptions = [
        'High protein', 'More vegetables', 'Less sugar', 'Less processed food',
        'High fibre', 'Low salt', 'Gluten-free', 'Dairy-free', 'Halal', 'Kosher',
    ];

    public array $allergyOptions = [
        'Peanuts', 'Tree nuts', 'Milk', 'Eggs', 'Soy', 'Gluten', 'Fish', 'Shellfish', 'Sesame',
    ];

    public function mount(): void
    {
        if (Auth::user()->hasCompletedOnboarding()) {
            $this->redirectRoute('home');
        }
    }

    public function selectGoal(string $goal): void
    {
        $this->primary_goal = $goal;
    }

    public function togglePreference(string $value): void
    {
        $this->toggle('dietary_preferences', $value);
    }

    public function toggleAllergy(string $value): void
    {
        $this->toggle('allergies', $value);
    }

    private function toggle(string $property, string $value): void
    {
        $current = $this->{$property};

        $this->{$property} = in_array($value, $current, true)
            ? array_values(array_filter($current, fn ($v) => $v !== $value))
            : [...$current, $value];
    }

    public function next(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'primary_goal' => ['required', Rule::enum(PrimaryGoal::class)],
            ], [], ['primary_goal' => 'goal']);
        }

        if ($this->step === 3) {
            $this->validateBodyInfo();
        }

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    private function validateBodyInfo(): void
    {
        $this->validate([
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'height_cm' => ['nullable', 'integer', 'min:50', 'max:260'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'activity_level' => ['nullable', Rule::enum(ActivityLevel::class)],
        ]);
    }

    public function complete(ProfileService $service): void
    {
        $this->validate([
            'primary_goal' => ['required', Rule::enum(PrimaryGoal::class)],
        ], [], ['primary_goal' => 'goal']);
        $this->validateBodyInfo();

        $avoided = collect(preg_split('/[\n,]+/', $this->avoided_foods))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->values()
            ->all();

        $service->completeOnboarding(Auth::user(), [
            'primary_goal' => $this->primary_goal,
            'dietary_pattern' => $this->dietary_pattern ?: null,
            'dietary_preferences' => $this->dietary_preferences,
            'allergies' => $this->allergies,
            'avoided_foods' => $avoided,
            'date_of_birth' => $this->date_of_birth ?: null,
            'sex' => $this->sex ?: null,
            'height_cm' => $this->height_cm !== null && $this->height_cm !== '' ? (int) $this->height_cm : null,
            'weight_kg' => $this->weight_kg !== null && $this->weight_kg !== '' ? (float) $this->weight_kg : null,
            'activity_level' => $this->activity_level ?: null,
        ]);

        $this->redirectRoute('home');
    }

    public function with(): array
    {
        return [
            'goals' => PrimaryGoal::options(),
            'patterns' => DietaryPattern::options(),
            'sexes' => Sex::options(),
            'activityLevels' => ActivityLevel::options(),
        ];
    }
}; ?>

<div class="flex flex-1 flex-col" x-data>
    {{-- Progress --}}
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <button type="button" wire:click="back"
                    class="text-sm font-medium text-ink-faint transition hover:text-ink-dim {{ $step === 1 ? 'invisible' : '' }}">
                Back
            </button>
            <span class="text-xs font-medium text-ink-faint">Step {{ $step }} of {{ self::LAST_STEP }}</span>
        </div>
        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-plate-raised">
            <div class="h-full rounded-full bg-emerald-600 transition-all duration-300"
                 style="width: {{ ($step / self::LAST_STEP) * 100 }}%"></div>
        </div>
    </div>

    {{-- Step 1 — Goal --}}
    @if ($step === 1)
        <div class="flex flex-1 flex-col">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">What brings you here?</h1>
            <p class="mt-2 text-sm leading-relaxed text-ink-dim">You can change this any time.</p>

            <div class="mt-6 space-y-2.5">
                @foreach ($goals as $goal)
                    <button type="button" wire:click="selectGoal('{{ $goal['value'] }}')"
                            class="w-full rounded-2xl border px-4 py-3.5 text-left transition {{ $primary_goal === $goal['value'] ? 'border-emerald-600 bg-emerald-50 ring-1 ring-emerald-600' : 'border-seam bg-plate hover:border-seam-strong' }}">
                        <p class="text-sm font-semibold text-ink">{{ $goal['label'] }}</p>
                        <p class="mt-0.5 text-xs text-ink-dim">{{ $goal['description'] }}</p>
                    </button>
                @endforeach
            </div>
            @error('primary_goal') <p class="mt-3 text-sm text-red-600">Please choose a goal to continue.</p> @enderror
        </div>
    @endif

    {{-- Step 2 — Preferences & allergies --}}
    @if ($step === 2)
        <div class="flex flex-1 flex-col">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Any preferences?</h1>
            <p class="mt-2 text-sm leading-relaxed text-ink-dim">
                Optional — tell us how you eat and anything you're allergic to, so guidance stays relevant and safe.
            </p>

            <div class="mt-6 space-y-6">
                <div>
                    <label class="text-sm font-medium text-ink-dim">Dietary pattern</label>
                    <select wire:model="dietary_pattern"
                            class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">No preference</option>
                        @foreach ($patterns as $p)
                            <option value="{{ $p['value'] }}">{{ $p['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <p class="text-sm font-medium text-ink-dim">I'd like to focus on</p>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        @foreach ($preferenceOptions as $option)
                            <button type="button" wire:click="togglePreference('{{ $option }}')"
                                    class="rounded-full border px-3 py-1.5 text-sm transition {{ in_array($option, $dietary_preferences, true) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-seam bg-plate text-ink-dim hover:border-seam-strong' }}">
                                {{ $option }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-ink-dim">Allergies</p>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        @foreach ($allergyOptions as $option)
                            <button type="button" wire:click="toggleAllergy('{{ $option }}')"
                                    class="rounded-full border px-3 py-1.5 text-sm transition {{ in_array($option, $allergies, true) ? 'border-red-500 bg-red-500 text-white' : 'border-seam bg-plate text-ink-dim hover:border-seam-strong' }}">
                                {{ $option }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-ink-dim">Foods you avoid <span class="text-ink-faint">(optional)</span></label>
                    <input type="text" wire:model="avoided_foods" placeholder="e.g. pork, coriander"
                           class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
            </div>
        </div>
    @endif

    {{-- Step 3 — Optional body info --}}
    @if ($step === 3)
        <div class="flex flex-1 flex-col">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">A little about you</h1>
            <p class="mt-2 text-sm leading-relaxed text-ink-dim">Optional — height and weight personalise your targets.</p>

            <div class="mt-6 space-y-5">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium text-ink-dim">Height (cm)</label>
                        <input type="number" inputmode="numeric" wire:model="height_cm" placeholder="175"
                               class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @error('height_cm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium text-ink-dim">Weight (kg)</label>
                        <input type="number" inputmode="decimal" step="0.1" wire:model="weight_kg" placeholder="70"
                               class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @error('weight_kg') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium text-ink-dim">Date of birth</label>
                        <input type="date" wire:model="date_of_birth"
                               class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm font-medium text-ink-dim">Sex</label>
                        <select wire:model="sex"
                                class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="">—</option>
                            @foreach ($sexes as $s)
                                <option value="{{ $s['value'] }}">{{ $s['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-ink-dim">Activity level</label>
                    <select wire:model="activity_level"
                            class="mt-2 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm text-ink focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">—</option>
                        @foreach ($activityLevels as $a)
                            <option value="{{ $a['value'] }}">{{ $a['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endif

    {{-- Step 4 — Confirmation --}}
    @if ($step === 4)
        <div class="flex flex-1 flex-col">
            <div class="mb-5 flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            </div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">You're all set</h1>
            <p class="mt-2 text-sm leading-relaxed text-ink-dim">
                Here's what we'll use to personalise your guidance. You can edit any of it later from your profile.
            </p>

            <dl class="mt-6 divide-y divide-seam rounded-2xl border border-seam">
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-sm text-ink-dim">Goal</dt>
                    <dd class="text-sm font-medium text-ink">{{ $primary_goal ? \App\Enums\PrimaryGoal::from($primary_goal)->label() : '—' }}</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-sm text-ink-dim">Dietary pattern</dt>
                    <dd class="text-sm font-medium text-ink">{{ $dietary_pattern ? \App\Enums\DietaryPattern::from($dietary_pattern)->label() : 'No preference' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4 px-4 py-3">
                    <dt class="text-sm text-ink-dim">Preferences</dt>
                    <dd class="text-right text-sm font-medium text-ink">{{ count($dietary_preferences) ? implode(', ', $dietary_preferences) : 'None' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4 px-4 py-3">
                    <dt class="text-sm text-ink-dim">Allergies</dt>
                    <dd class="text-right text-sm font-medium {{ count($allergies) ? 'text-red-600' : 'text-ink' }}">{{ count($allergies) ? implode(', ', $allergies) : 'None' }}</dd>
                </div>
            </dl>

            <p class="mt-5 text-xs leading-relaxed text-ink-faint">
                This app offers general nutrition guidance only and is not medical advice.
            </p>
        </div>
    @endif

    {{-- Footer actions --}}
    <div class="mt-8 space-y-3">
        @if ($step < self::LAST_STEP)
            <button type="button" wire:click="next"
                    class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                Continue
            </button>
            @if ($step > 1)
                <button type="button" wire:click="next"
                        class="w-full py-1 text-center text-sm font-medium text-ink-faint transition hover:text-ink-dim">
                    Skip for now
                </button>
            @endif
        @else
            <button type="button" wire:click="complete" wire:loading.attr="disabled"
                    class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-60">
                <span wire:loading.remove wire:target="complete">Enter the app</span>
                <span wire:loading wire:target="complete">Saving…</span>
            </button>
        @endif
    </div>
</div>
