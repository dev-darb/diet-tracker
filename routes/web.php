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

        // Pantry — "what do I currently have" list + tap-through item detail (J1.5).
        Volt::route('pantry', 'pantry')->name('pantry');
        Volt::route('pantry/{pantryItem}', 'pantry-item')->name('pantry.item');

        // Scan — capture -> on-device barcode -> resolve -> confirm -> quantity -> pantry (J2.5).
        Volt::route('scan', 'scan')->name('scan');
        Route::view('eat', 'eat')->name('eat');
        Route::view('health', 'health')->name('health');

        Volt::route('profile', 'profile')->name('profile');

        Route::redirect('settings', 'settings/password');
        Volt::route('settings/password', 'settings.password')->name('settings.password');
        Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    });

    // Admin console (BUILD_PLAN §11) — Products section for Milestone 1.
    Route::middleware('admin')->group(function () {
        Route::redirect('admin', 'admin/products');
        Volt::route('admin/products', 'admin.products.index')->name('admin.products.index');
        Volt::route('admin/products/create', 'admin.products.create')->name('admin.products.create');
        Volt::route('admin/products/{product}/edit', 'admin.products.edit')->name('admin.products.edit');
    });
});

require __DIR__.'/auth.php';
