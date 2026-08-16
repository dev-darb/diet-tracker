<?php

use App\Livewire\Actions\Logout;
use App\Services\ProfileService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    /**
     * Permanently delete the user and all of their data (brief §6.4, §21 Q45).
     * The actual deletion lives in ProfileService (thin-component rule).
     */
    public function deleteUser(Logout $logout, ProfileService $profiles): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();

        $logout();

        $profiles->deleteAccount($user);

        $this->redirect('/');
    }
}; ?>

{{-- Deleting an account is the one act that cannot be undone, so it is the
     one place a blocking confirm is genuinely protective (the undo grammar
     covers everything reversible). A disclosure, not a modal: the console
     has no modal vocabulary. --}}
<div x-data="{ confirming: false }">
    <h2 class="silkscreen">Delete account</h2>
    <p class="voice-caption mt-1.5 text-ink-dim">Your account and everything in it — pantry, log, history — permanently.</p>

    <button type="button" x-show="!confirming" x-on:click="confirming = true"
            class="key keycap-sm mt-3 px-3.5 py-2 !border-high/50 text-high">
        Delete account
    </button>

    <div class="split" :class="confirming && 'split-open'" :inert="!confirming">
    <div>
    <form wire:submit="deleteUser" class="mt-3 space-y-3">
        <x-app.field label="Password" name="delete_password" type="password" model="password"
                     autocomplete="current-password" />
        <div class="flex items-center gap-2">
            <button type="submit" class="key keycap-sm px-3.5 py-2 !border-high !bg-high !text-black">
                Delete permanently
            </button>
            <button type="button" x-on:click="confirming = false"
                    class="keycap-sm hit px-2 py-2 text-ink-dim transition hover:text-ink">Cancel</button>
        </div>
    </form>
    </div>
    </div>
</div>
