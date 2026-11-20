<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\SpendReportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('gallery', GalleryController::class)->name('generations.gallery');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations/{generation}/image', GenerationImageController::class)->name('generations.image');
    Route::resource('credits', CreditController::class)->only(['index', 'store']);
    Route::get('admin/spend-report', SpendReportController::class)->name('admin.spend-report');
    Route::get('admin/users', [UserController::class, 'index'])->name('admin.users.index');
    Route::post('admin/users/{user}/impersonate', [ImpersonationController::class, 'store'])->name('admin.impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');

    Route::get('generations', RedirectController::class)->defaults('destination', '/dashboard')->defaults('status', 301);
    Route::get('generations/create', RedirectController::class)->defaults('destination', '/generate')->defaults('status', 301);
});

require __DIR__.'/settings.php';
