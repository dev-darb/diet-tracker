<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $password = '';

    /**
     * Confirm the current user's password.
     */
    public function confirmPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: route('home', absolute: false));
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-auth-header title="Confirm your password" description="This one needs your password again." />

    <form wire:submit="confirmPassword" class="flex flex-col gap-4">
        <x-app.field label="Password" name="password" type="password" model="password" required autofocus autocomplete="current-password" />

        <x-app.console-key primary type="submit">Confirm</x-app.console-key>
    </form>
</div>
