<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('home', absolute: false));

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/');
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-auth-header title="Verify your email"
                   description="We've sent you a link. Open it and you're in." />

    @if (session('status') == 'verification-link-sent')
        <x-auth-session-status status="A fresh link is on its way." />
    @endif

    <div class="flex flex-col gap-2">
        <x-app.console-key wire:click="sendVerification">Send it again</x-app.console-key>
        <button type="button" wire:click="logout" class="keycap-sm hit py-2 text-ink-faint transition hover:text-ink">Log out</button>
    </div>
</div>
