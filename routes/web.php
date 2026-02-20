<?php

use App\Http\Controllers\DeploymentController;
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

    // Redirect standalone /sites to /servers (sites are always in server context)
    Route::get('sites', fn () => redirect()->route('servers.index'))->name('sites.redirect');

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

    // Sites and deployments (nested under servers) – scopeBindings ensures site belongs to server, deployment to site
    Route::middleware(['can:view,server'])->scopeBindings()->group(function () {
        // Sites index for a server
        Route::get('servers/{server}/sites', [SiteController::class, 'index'])
            ->name('servers.sites.index');
        Route::get('servers/{server}/sites/create', [SiteController::class, 'create'])
            ->name('servers.sites.create');
        Route::post('servers/{server}/sites', [SiteController::class, 'store'])
            ->name('servers.sites.store');

        // Site show, edit, update, destroy
        Route::get('servers/{server}/sites/{site}', [SiteController::class, 'show'])
            ->name('servers.sites.show');
        Route::get('servers/{server}/sites/{site}/edit', [SiteController::class, 'edit'])
            ->name('servers.sites.edit');
        Route::put('servers/{server}/sites/{site}', [SiteController::class, 'update'])
            ->name('servers.sites.update');
        Route::delete('servers/{server}/sites/{site}', [SiteController::class, 'destroy'])
            ->name('servers.sites.destroy');

        // Site environment & deploy script
        Route::get('servers/{server}/sites/{site}/environment', [EnvironmentController::class, 'show'])
            ->name('servers.sites.environment.show');
        Route::put('servers/{server}/sites/{site}/environment', [EnvironmentController::class, 'update'])
            ->name('servers.sites.environment.update');
        Route::get('servers/{server}/sites/{site}/deploy-script', [DeployScriptController::class, 'show'])
            ->name('servers.sites.deploy-script.show');
        Route::put('servers/{server}/sites/{site}/deploy-script', [DeployScriptController::class, 'update'])
            ->name('servers.sites.deploy-script.update');

        // Deployments (under server/site)
        Route::get('servers/{server}/sites/{site}/deployments', [DeploymentController::class, 'index'])
            ->name('servers.sites.deployments.index');
        Route::post('servers/{server}/sites/{site}/deployments', [DeploymentController::class, 'store'])
            ->name('servers.sites.deployments.store');
        Route::get('servers/{server}/sites/{site}/deployments/{deployment}', [DeploymentController::class, 'show'])
            ->name('servers.sites.deployments.show');
    });

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
    Route::get('source-control/{sourceControlAccount}/repositories/{repository}/branches', [\App\Http\Controllers\SourceControlAccountController::class, 'branches'])
        ->where('repository', '.*')
        ->name('source-control.branches');
});

require __DIR__.'/settings.php';
