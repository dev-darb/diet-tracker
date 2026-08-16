<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('home', absolute: false));
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-auth-header title="Log in" description="Your kitchen, where you left it." />

    <x-auth-session-status :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-4">
        <x-app.field label="Email" name="email" type="email" model="email" required autofocus autocomplete="email" />

        <x-app.field label="Password" name="password" type="password" model="password" required autocomplete="current-password" />

        {{-- A binary state in the world's own device: an unlit cell that
             lights when it's on. The browser's default box is off-world. --}}
        <label class="flex items-center gap-2.5">
            <input type="checkbox" wire:model="remember"
                   class="size-4 shrink-0 appearance-none rounded-[2px] border border-seam-strong bg-plate-well transition checked:border-action checked:bg-action">
            <span class="voice-caption text-ink-dim">Stay logged in</span>
        </label>

        <x-app.console-key primary type="submit">Log in</x-app.console-key>
    </form>

    <div class="flex items-center justify-between gap-3 border-t border-seam pt-3">
        <x-text-link href="{{ route('register') }}">Create an account</x-text-link>
        @if (Route::has('password.request'))
            <x-text-link href="{{ route('password.request') }}">Forgot password?</x-text-link>
        @endif
    </div>
</div>
