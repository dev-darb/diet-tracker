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
 * Profile / settings hub (brief §6.4): view & edit account, goal, preferences,
 * allergies, basic health profile, plus privacy/data controls, logout and
 * account deletion. All writes go through ProfileService (thin-component rule).
 */
new #[Layout('components.layouts.app', ['title' => 'Profile'])] class extends Component {
    // Account
    public string $name = '';
    public string $email = '';

    // Profile
    public string $primary_goal = '';
    public string $dietary_pattern = '';
    /** @var array<int, string> */
    public array $dietary_preferences = [];
    /** @var array<int, string> */
    public array $allergies = [];
    public string $avoided_foods = '';
    public string $date_of_birth = '';
    public string $sex = '';
    public ?string $height_cm = null;
    public ?string $weight_kg = null;
    public string $activity_level = '';

    // Manual targets (Foody Score spec §4): empty = derived from the profile.
    public ?string $custom_calorie_target = null;
    public ?string $custom_protein_g = null;
    public ?string $custom_carbs_g = null;
    public ?string $custom_fat_g = null;

    public array $preferenceOptions = [
        'High protein', 'More vegetables', 'Less sugar', 'Less processed food',
        'High fibre', 'Low salt', 'Gluten-free', 'Dairy-free', 'Halal', 'Kosher',
    ];

    public array $allergyOptions = [
        'Peanuts', 'Tree nuts', 'Milk', 'Eggs', 'Soy', 'Gluten', 'Fish', 'Shellfish', 'Sesame',
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;

        $profile = $user->profile;

        if ($profile) {
            $this->primary_goal = $profile->primary_goal?->value ?? '';
            $this->dietary_pattern = $profile->dietary_pattern?->value ?? '';
            $this->dietary_preferences = $profile->dietary_preferences ?? [];
            $this->allergies = $profile->allergies ?? [];
            $this->avoided_foods = implode(', ', $profile->avoided_foods ?? []);
            $this->date_of_birth = $profile->date_of_birth?->format('Y-m-d') ?? '';
            $this->sex = $profile->sex?->value ?? '';
            $this->height_cm = $profile->height_cm !== null ? (string) $profile->height_cm : null;
            $this->weight_kg = $profile->weight_kg !== null ? (string) $profile->weight_kg : null;
            $this->activity_level = $profile->activity_level?->value ?? '';
            $this->custom_calorie_target = $profile->custom_calorie_target !== null ? (string) $profile->custom_calorie_target : null;
            $this->custom_protein_g = $profile->custom_protein_g !== null ? (string) $profile->custom_protein_g : null;
            $this->custom_carbs_g = $profile->custom_carbs_g !== null ? (string) $profile->custom_carbs_g : null;
            $this->custom_fat_g = $profile->custom_fat_g !== null ? (string) $profile->custom_fat_g : null;
        }
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

    public function saveAccount(ProfileService $service): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore(Auth::id())],
        ]);

        $service->updateAccount(Auth::user(), $validated);

        $this->dispatch('saved', section: 'account');
    }

    public function saveProfile(ProfileService $service): void
    {
        $this->validate([
            'primary_goal' => ['required', Rule::enum(PrimaryGoal::class)],
            'dietary_pattern' => ['nullable', Rule::enum(DietaryPattern::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'height_cm' => ['nullable', 'integer', 'min:50', 'max:260'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'activity_level' => ['nullable', Rule::enum(ActivityLevel::class)],
            'custom_calorie_target' => ['nullable', 'integer', 'min:800', 'max:6000'],
            'custom_protein_g' => ['nullable', 'numeric', 'min:10', 'max:400'],
            'custom_carbs_g' => ['nullable', 'numeric', 'min:10', 'max:900'],
            'custom_fat_g' => ['nullable', 'numeric', 'min:10', 'max:300'],
        ], [], ['primary_goal' => 'goal']);

        $avoided = collect(preg_split('/[\n,]+/', $this->avoided_foods))
            ->map(fn ($v) => trim($v))->filter()->values()->all();

        $service->updateProfile(Auth::user(), [
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
            'custom_calorie_target' => $this->custom_calorie_target !== null && $this->custom_calorie_target !== '' ? (int) $this->custom_calorie_target : null,
            'custom_protein_g' => $this->custom_protein_g !== null && $this->custom_protein_g !== '' ? (float) $this->custom_protein_g : null,
            'custom_carbs_g' => $this->custom_carbs_g !== null && $this->custom_carbs_g !== '' ? (float) $this->custom_carbs_g : null,
            'custom_fat_g' => $this->custom_fat_g !== null && $this->custom_fat_g !== '' ? (float) $this->custom_fat_g : null,
        ]);

        $this->dispatch('saved', section: 'profile');
    }

    public function with(): array
    {
        // Live targets, receipts included — the placeholders show what Foody
        // derives so an override is always a choice against a visible default.
        $targets = app(\App\Services\NutritionTargetsService::class)->targetsFor(Auth::user());

        return [
            'goals' => PrimaryGoal::options(),
            'patterns' => DietaryPattern::options(),
            'sexes' => Sex::options(),
            'activityLevels' => ActivityLevel::options(),
            'derived' => [
                'calories' => (int) ($targets['calories']['default'] ?? $targets['calories']['target']),
                'protein' => (int) ($targets['protein']['default'] ?? $targets['protein']['target']),
                'carbs' => (int) ($targets['carbs']['default'] ?? $targets['carbs']['target']),
                'fat' => (int) ($targets['fat']['default'] ?? $targets['fat']['target']),
            ],
        ];
    }
}; ?>

    <div class="space-y-6" x-data="{ saved: null }"
         x-on:saved.window="saved = $event.detail.section; setTimeout(() => saved = null, 2500)">

        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">Profile</h1>
        </div>

        {{-- Account --}}
        <section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-ink">Account</h2>
                <span x-show="saved === 'account'" x-cloak class="text-xs font-medium text-emerald-600">Saved</span>
            </div>
            <form wire:submit="saveAccount" class="mt-4 space-y-4">
                <div>
                    <label class="text-sm font-medium text-ink-dim">Name</label>
                    <input type="text" wire:model="name"
                           class="mt-1.5 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-sm font-medium text-ink-dim">Email</label>
                    <input type="email" wire:model="email"
                           class="mt-1.5 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                    Save account
                </button>
            </form>
        </section>

        {{-- Goal & preferences --}}
        <section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-ink">Goal &amp; preferences</h2>
                <span x-show="saved === 'profile'" x-cloak class="text-xs font-medium text-emerald-600">Saved</span>
            </div>
            <form wire:submit="saveProfile" class="mt-4 space-y-5">
                <div>
                    <label class="text-sm font-medium text-ink-dim">Primary goal</label>
                    <select wire:model="primary_goal"
                            class="mt-1.5 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">Choose a goal…</option>
                        @foreach ($goals as $goal)
                            <option value="{{ $goal['value'] }}">{{ $goal['label'] }}</option>
                        @endforeach
                    </select>
                    @error('primary_goal') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-sm font-medium text-ink-dim">Dietary pattern</label>
                    <select wire:model="dietary_pattern"
                            class="mt-1.5 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">No preference</option>
                        @foreach ($patterns as $p)
                            <option value="{{ $p['value'] }}">{{ $p['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <p class="text-sm font-medium text-ink-dim">Focus areas</p>
                    <div class="mt-2 flex flex-wrap gap-2">
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
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($allergyOptions as $option)
                            <button type="button" wire:click="toggleAllergy('{{ $option }}')"
                                    class="rounded-full border px-3 py-1.5 text-sm transition {{ in_array($option, $allergies, true) ? 'border-red-500 bg-red-500 text-white' : 'border-seam bg-plate text-ink-dim hover:border-seam-strong' }}">
                                {{ $option }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-ink-dim">Foods you avoid</label>
                    <input type="text" wire:model="avoided_foods" placeholder="e.g. pork, coriander"
                           class="mt-1.5 w-full rounded-xl border border-seam bg-plate px-3 py-2.5 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                {{-- Basic health profile (optional, progressive disclosure) --}}
                <div class="rounded-xl bg-plate-well p-4">
                    <p class="text-sm font-medium text-ink-dim">Basic health profile</p>
                    <p class="mt-0.5 text-xs text-ink-dim">Optional — adding height and weight lets us personalise energy and protein guidance.</p>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-ink-dim">Height (cm)</label>
                            <input type="number" inputmode="numeric" wire:model="height_cm"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('height_cm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs text-ink-dim">Weight (kg)</label>
                            <input type="number" inputmode="decimal" step="0.1" wire:model="weight_kg"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('weight_kg') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs text-ink-dim">Date of birth</label>
                            <input type="date" wire:model="date_of_birth"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs text-ink-dim">Sex</label>
                            <select wire:model="sex"
                                    class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <option value="">—</option>
                                @foreach ($sexes as $s)
                                    <option value="{{ $s['value'] }}">{{ $s['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs text-ink-dim">Activity level</label>
                            <select wire:model="activity_level"
                                    class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <option value="">—</option>
                                @foreach ($activityLevels as $a)
                                    <option value="{{ $a['value'] }}">{{ $a['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Manual targets (Foody Score spec §4): explicit targets become
                     the scoring targets and are judged with tighter tolerance. --}}
                <div class="rounded-xl bg-plate-well p-4">
                    <p class="text-sm font-medium text-ink-dim">Your own targets</p>
                    <p class="mt-0.5 text-xs text-ink-dim">Optional — leave blank to use the figures Foody works out for you. A target you set here is treated as deliberate and scored more precisely.</p>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-ink-dim">Calories (kcal)</label>
                            <input type="number" inputmode="numeric" wire:model="custom_calorie_target" placeholder="{{ $derived['calories'] }}"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('custom_calorie_target') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs text-ink-dim">Protein (g)</label>
                            <input type="number" inputmode="decimal" step="1" wire:model="custom_protein_g" placeholder="{{ $derived['protein'] }}"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('custom_protein_g') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs text-ink-dim">Carbs (g)</label>
                            <input type="number" inputmode="decimal" step="1" wire:model="custom_carbs_g" placeholder="{{ $derived['carbs'] }}"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('custom_carbs_g') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs text-ink-dim">Fat (g)</label>
                            <input type="number" inputmode="decimal" step="1" wire:model="custom_fat_g" placeholder="{{ $derived['fat'] }}"
                                   class="mt-1 w-full rounded-lg border border-seam bg-plate px-3 py-2 text-sm placeholder:text-ink-faint focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @error('custom_fat_g') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <button type="submit" class="rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                    Save preferences
                </button>
            </form>
        </section>

        {{-- Security / other settings --}}
        <section class="divide-y divide-seam overflow-hidden rounded-2xl border border-seam bg-plate shadow-sm">
            <a href="{{ route('settings.password') }}" class="flex items-center justify-between px-5 py-4 transition hover:bg-plate-well">
                <span class="text-sm font-medium text-ink">Password</span>
                <svg class="size-4 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </a>
        </section>

        {{-- Privacy / data --}}
        <section class="rounded-2xl border border-seam bg-plate p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-ink">Privacy &amp; data</h2>
            <p class="mt-2 text-xs leading-relaxed text-ink-dim">
                Private to your account. General guidance, not medical advice.
            </p>
        </section>

        {{-- Logout --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full rounded-xl border border-seam bg-plate px-4 py-3 text-sm font-semibold text-ink-dim transition hover:bg-plate-well">
                Log out
            </button>
        </form>

        {{-- Delete account --}}
        <div class="rounded-2xl border border-red-100 bg-red-50/50 p-5">
            <livewire:settings.delete-user-form />
        </div>
    </div>

