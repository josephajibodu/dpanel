<?php

use App\Http\Controllers\ProviderAccountController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SshKeyController;
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
    Route::post('servers/{server}/restart', [ServerController::class, 'restart'])
        ->name('servers.restart');

    // SSH Keys
    Route::resource('ssh-keys', SshKeyController::class)
        ->only(['index', 'store', 'destroy']);
    Route::post('ssh-keys/{sshKey}/sync', [SshKeyController::class, 'sync'])
        ->name('ssh-keys.sync');
    Route::post('ssh-keys/{sshKey}/revoke', [SshKeyController::class, 'revoke'])
        ->name('ssh-keys.revoke');
});

require __DIR__.'/settings.php';
