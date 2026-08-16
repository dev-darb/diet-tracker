<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        session()->flash('status', __('A reset link will be sent if the account exists.'));
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-auth-header title="Reset your password" description="We'll email you a link to set a new one." />

    <x-auth-session-status :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-4">
        <x-app.field label="Email" name="email" type="email" model="email" required autofocus />

        <x-app.console-key primary type="submit">Email the link</x-app.console-key>
    </form>

    <div class="border-t border-seam pt-3">
        <x-text-link href="{{ route('login') }}">Back to log in</x-text-link>
    </div>
</div>
