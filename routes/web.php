<?php

use App\Http\Controllers\ProviderAccountController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Provider Accounts
    Route::resource('provider-accounts', ProviderAccountController::class)
        ->except(['edit', 'update']);
    Route::post('provider-accounts/{providerAccount}/validate', [ProviderAccountController::class, 'validate'])
        ->name('provider-accounts.validate');

    // Servers
    Route::resource('servers', ServerController::class)
        ->except(['edit', 'update']);
});

require __DIR__.'/settings.php';
