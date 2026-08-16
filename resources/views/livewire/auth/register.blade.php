<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered(($user = User::create($validated))));

        Auth::login($user);

        $this->redirect(route('home', absolute: false));
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-auth-header title="Create your account" description="Scan a shop, log a meal, see how it adds up." />

    <form wire:submit="register" class="flex flex-col gap-4">
        <x-app.field label="Name" name="name" model="name" required autofocus autocomplete="name" />

        <x-app.field label="Email" name="email" type="email" model="email" required autocomplete="email" />

        <x-app.field label="Password" name="password" type="password" model="password" required autocomplete="new-password" />

        <x-app.field label="Confirm password" name="password_confirmation" type="password" model="password_confirmation" required autocomplete="new-password" />

        <x-app.console-key primary type="submit">Create account</x-app.console-key>
    </form>

    <div class="border-t border-seam pt-3">
        <x-text-link href="{{ route('login') }}">Already have an account? Log in</x-text-link>
    </div>
</div>
