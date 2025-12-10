<?php

use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Livewire\Volt\Volt;

Route::get('/', fn (): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('welcome'));

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function (): void {
    // Dashboard redirects to lists
    Route::redirect('dashboard', '/lists')->name('dashboard');

    // Lists management
    Volt::route('lists', 'lists.index')->name('lists.index');
    Volt::route('lists/create', 'lists.create')->name('lists.create');
    Volt::route('lists/{list}', 'lists.show')->name('lists.show');
    Volt::route('lists/{list}/settings', 'lists.settings')->name('lists.settings');
});

// Public profile routes (no auth required)
Volt::route('@{username}', 'profile.show')->name('profile.show');
Volt::route('@{username}/{slug}', 'profile.list')->name('profile.list');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
