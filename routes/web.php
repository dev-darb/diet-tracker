<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('home')
        : view('welcome');
})->name('welcome');

Route::middleware(['auth'])->group(function () {
    // Onboarding runs before the onboarded gate (brief §6.3/§6.4).
    Volt::route('onboarding', 'onboarding')->name('onboarding');

    // Main app — only reachable once onboarding is complete.
    Route::middleware('onboarded')->group(function () {
        Route::view('home', 'home')->name('home');
        Route::view('pantry', 'pantry')->name('pantry');
        Route::view('scan', 'scan')->name('scan');
        Route::view('eat', 'eat')->name('eat');
        Route::view('health', 'health')->name('health');

        Volt::route('profile', 'profile')->name('profile');

        Route::redirect('settings', 'settings/password');
        Volt::route('settings/password', 'settings.password')->name('settings.password');
        Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    });
});

require __DIR__.'/auth.php';
