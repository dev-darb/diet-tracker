<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<div class="space-y-4" x-data="{ saved: false }"
     x-on:password-updated.window="saved = true; setTimeout(() => saved = false, 2500)">

    <a href="{{ route('profile') }}" wire:navigate class="keycap-sm inline-flex items-center gap-1.5 px-1 text-ink-dim transition hover:text-ink">
        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
        Profile
    </a>

    <section class="module px-5 pb-5 pt-4">
        <div class="flex items-center justify-between">
            <h1 class="silkscreen">Password</h1>
            <span x-show="saved" x-cloak class="data-sm text-good uppercase">Saved</span>
        </div>

        <form wire:submit="updatePassword" class="mt-4 space-y-4">
            <x-app.field label="Current password" name="current_password" type="password"
                         model="current_password" required autocomplete="current-password" />

            <x-app.field label="New password" name="password" type="password"
                         model="password" required autocomplete="new-password" />

            <x-app.field label="Confirm password" name="password_confirmation" type="password"
                         model="password_confirmation" required autocomplete="new-password" />

            <x-app.console-key primary type="submit">Save the password</x-app.console-key>
        </form>
    </section>
</div>
