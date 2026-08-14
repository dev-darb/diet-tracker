<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Appearance" subheading="How foody looks">
        {{-- foody is a single committed dark console world; the light/system
             toggle was retired with the console redesign (design brief, Aug 2026). --}}
        <div class="module flex items-center gap-3 px-4 py-3">
            <span class="size-2 shrink-0 rounded-full bg-action" aria-hidden="true"></span>
            <p class="text-sm text-ink-dim">
                foody runs in <span class="text-ink">console dark</span> — one committed look,
                tuned for glanceable readouts in the kitchen and at night.
            </p>
        </div>
    </x-settings.layout>
</div>
