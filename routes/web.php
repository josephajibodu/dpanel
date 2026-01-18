<?php

use App\Http\Controllers\DeployScriptController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\ProviderAccountController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SiteController;
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

    // Sites (nested under servers for creation)
    Route::get('servers/{server}/sites/create', [SiteController::class, 'create'])
        ->name('servers.sites.create');
    Route::post('servers/{server}/sites', [SiteController::class, 'store'])
        ->name('servers.sites.store');

    // Sites (standalone routes)
    Route::resource('sites', SiteController::class)
        ->only(['show', 'edit', 'update', 'destroy']);

    // Site Environment & Deploy Script
    Route::put('sites/{site}/environment', [EnvironmentController::class, 'update'])
        ->name('sites.environment.update');
    Route::put('sites/{site}/deploy-script', [DeployScriptController::class, 'update'])
        ->name('sites.deploy-script.update');

    // SSH Keys
    Route::resource('ssh-keys', SshKeyController::class)
        ->only(['index', 'store', 'destroy']);
    Route::post('ssh-keys/{sshKey}/sync', [SshKeyController::class, 'sync'])
        ->name('ssh-keys.sync');
    Route::post('ssh-keys/{sshKey}/revoke', [SshKeyController::class, 'revoke'])
        ->name('ssh-keys.revoke');

    // Source Control Accounts
    Route::get('source-control', [\App\Http\Controllers\SourceControlAccountController::class, 'index'])
        ->name('source-control.index');
    Route::get('auth/{provider}/redirect', [\App\Http\Controllers\SourceControlAccountController::class, 'redirect'])
        ->name('source-control.redirect')
        ->where('provider', 'github|gitlab|bitbucket');
    Route::get('auth/{provider}/callback', [\App\Http\Controllers\SourceControlAccountController::class, 'callback'])
        ->name('source-control.callback')
        ->where('provider', 'github|gitlab|bitbucket');
    Route::delete('source-control/{sourceControlAccount}', [\App\Http\Controllers\SourceControlAccountController::class, 'destroy'])
        ->name('source-control.destroy');
});

require __DIR__.'/settings.php';
