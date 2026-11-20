<?php

use App\Http\Controllers\GenerationController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('generations', RedirectController::class)->defaults('destination', '/dashboard')->defaults('status', 301);
    Route::get('generations/create', RedirectController::class)->defaults('destination', '/dashboard')->defaults('status', 301);
});

require __DIR__.'/settings.php';
