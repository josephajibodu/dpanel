<?php

use App\Http\Controllers\CronJobController;
use App\Http\Controllers\CurrentTeamController;
use App\Http\Controllers\DatabaseUserController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeploymentWebhookController;
use App\Http\Controllers\DeployScriptController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\ProviderAccountController;
use App\Http\Controllers\RealtimeDiagnosticsController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerDatabaseController;
use App\Http\Controllers\ServerPhpController;
use App\Http\Controllers\ServerProcessController;
use App\Http\Controllers\SiteCommandRunController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteDomainController;
use App\Http\Controllers\SshKeyController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\WorkerController;
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

    Route::get('realtime/test', [RealtimeDiagnosticsController::class, 'index'])
        ->name('realtime.test');
    Route::post('realtime/test/trigger', [RealtimeDiagnosticsController::class, 'trigger'])
        ->name('realtime.test.trigger');

    // SSH Keys (personal — no team prefix)
    Route::resource('ssh-keys', SshKeyController::class)
        ->only(['index', 'store', 'destroy']);
    Route::post('ssh-keys/{sshKey}/sync', [SshKeyController::class, 'sync'])
        ->name('ssh-keys.sync');
    Route::post('ssh-keys/{sshKey}/revoke', [SshKeyController::class, 'revoke'])
        ->name('ssh-keys.revoke');

    // Source Control Accounts (personal — no team prefix)
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
    Route::get('source-control/{sourceControlAccount}/repositories', [\App\Http\Controllers\SourceControlAccountController::class, 'repositories'])
        ->name('source-control.repositories');
    Route::get('source-control/{sourceControlAccount}/repositories/{repository}/branches', [\App\Http\Controllers\SourceControlAccountController::class, 'branches'])
        ->where('repository', '.*')
        ->name('source-control.branches');

    // Teams management (no team-slug prefix — these manage the team entity itself)
    Route::get('teams/slug-suggestion', [TeamController::class, 'slugSuggestion'])->name('teams.slug-suggestion');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('teams/{team}/settings', [TeamController::class, 'show'])->name('teams.show');
    Route::put('teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');

    Route::post('teams/{team}/members', [TeamMemberController::class, 'store'])->name('team-members.store');
    Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('team-members.destroy');

    Route::post('teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('team-invitations.store');
    Route::delete('teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('team-invitations.destroy');

    Route::put('user/current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');

    // -------------------------------------------------------------------------
    // Team-scoped resources — prefixed with /{team:slug}/
    // The 'team.slug' middleware resolves the slug, verifies membership, and
    // auto-switches current_team_id when following a shared link.
    // -------------------------------------------------------------------------
    Route::middleware(['team.slug'])
        ->prefix('{team}')
        ->group(function () {

            // Redirect /{team}/sites → /{team}/servers
            Route::get('sites', fn ($team) => redirect()->route('servers.index', $team))
                ->name('sites.redirect');

            // Provider Accounts
            Route::resource('provider-accounts', ProviderAccountController::class)
                ->except(['edit', 'update']);
            Route::post('provider-accounts/{providerAccount}/validate', [ProviderAccountController::class, 'validate'])
                ->name('provider-accounts.validate');

            // Servers
            Route::get('servers/generate-name', [ServerController::class, 'generateName'])
                ->name('servers.generate-name');
            Route::resource('servers', ServerController::class)
                ->except(['edit', 'update']);
            Route::post('servers/{server}/restart', [ServerController::class, 'restart'])
                ->name('servers.restart');

            // Sites and all nested server resources
            Route::middleware(['can:view,server'])->scopeBindings()->group(function () {
                // Sites
                Route::get('servers/{server}/sites', [SiteController::class, 'index'])
                    ->name('servers.sites.index');
                Route::get('servers/{server}/sites/create', [SiteController::class, 'create'])
                    ->name('servers.sites.create');
                Route::post('servers/{server}/sites', [SiteController::class, 'store'])
                    ->name('servers.sites.store');
                Route::get('servers/{server}/sites/{site}', [SiteController::class, 'show'])
                    ->name('servers.sites.show');
                Route::get('servers/{server}/sites/{site}/edit', [SiteController::class, 'edit'])
                    ->name('servers.sites.edit');
                Route::put('servers/{server}/sites/{site}', [SiteController::class, 'update'])
                    ->name('servers.sites.update');
                Route::delete('servers/{server}/sites/{site}', [SiteController::class, 'destroy'])
                    ->name('servers.sites.destroy');

                // Domains
                Route::get('servers/{server}/sites/{site}/domains', [SiteDomainController::class, 'index'])
                    ->name('servers.sites.domains.index');
                Route::post('servers/{server}/sites/{site}/domains/validate', [SiteDomainController::class, 'validateHostname'])
                    ->name('servers.sites.domains.validate');
                Route::post('servers/{server}/sites/{site}/domains', [SiteDomainController::class, 'store'])
                    ->name('servers.sites.domains.store');
                Route::patch('servers/{server}/sites/{site}/domains/{site_domain}', [SiteDomainController::class, 'update'])
                    ->name('servers.sites.domains.update');
                Route::delete('servers/{server}/sites/{site}/domains/{site_domain}', [SiteDomainController::class, 'destroy'])
                    ->name('servers.sites.domains.destroy');
                Route::post('servers/{server}/sites/{site}/domains/{site_domain}/primary', [SiteDomainController::class, 'setPrimary'])
                    ->name('servers.sites.domains.primary');

                // Environment & deploy script
                Route::get('servers/{server}/sites/{site}/environment', [EnvironmentController::class, 'show'])
                    ->name('servers.sites.environment.show');
                Route::put('servers/{server}/sites/{site}/environment', [EnvironmentController::class, 'update'])
                    ->name('servers.sites.environment.update');
                Route::get('servers/{server}/sites/{site}/deploy-script', [DeployScriptController::class, 'show'])
                    ->name('servers.sites.deploy-script.show');
                Route::put('servers/{server}/sites/{site}/deploy-script', [DeployScriptController::class, 'update'])
                    ->name('servers.sites.deploy-script.update');

                // Deployments
                Route::get('servers/{server}/sites/{site}/deployments', [DeploymentController::class, 'index'])
                    ->name('servers.sites.deployments.index');
                Route::post('servers/{server}/sites/{site}/deployments', [DeploymentController::class, 'store'])
                    ->name('servers.sites.deployments.store');
                Route::get('servers/{server}/sites/{site}/deployments/{deployment}', [DeploymentController::class, 'show'])
                    ->name('servers.sites.deployments.show');
                Route::post('servers/{server}/sites/{site}/deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])
                    ->name('servers.sites.deployments.cancel');

                // Command runs
                Route::get('servers/{server}/sites/{site}/command-runs', [SiteCommandRunController::class, 'index'])
                    ->name('servers.sites.command-runs.index');
                Route::post('servers/{server}/sites/{site}/command-runs', [SiteCommandRunController::class, 'store'])
                    ->name('servers.sites.command-runs.store');
                Route::get('servers/{server}/sites/{site}/command-runs/{command_run}', [SiteCommandRunController::class, 'show'])
                    ->name('servers.sites.command-runs.show');

                // Databases and database users
                Route::get('servers/{server}/databases', [ServerDatabaseController::class, 'index'])
                    ->name('servers.databases.index');
                Route::post('servers/{server}/databases', [ServerDatabaseController::class, 'store'])
                    ->name('servers.databases.store');
                Route::delete('servers/{server}/databases/{server_database}', [ServerDatabaseController::class, 'destroy'])
                    ->name('servers.databases.destroy');
                Route::post('servers/{server}/database-users', [DatabaseUserController::class, 'store'])
                    ->name('servers.database-users.store');
                Route::put('servers/{server}/database-users/{database_user}', [DatabaseUserController::class, 'update'])
                    ->name('servers.database-users.update');
                Route::delete('servers/{server}/database-users/{database_user}', [DatabaseUserController::class, 'destroy'])
                    ->name('servers.database-users.destroy');

                // PHP
                Route::get('servers/{server}/php', [ServerPhpController::class, 'index'])
                    ->name('servers.php.index');
                Route::put('servers/{server}/php/settings', [ServerPhpController::class, 'updateSettings'])
                    ->name('servers.php.settings.update');
                Route::post('servers/{server}/php/versions', [ServerPhpController::class, 'installVersion'])
                    ->name('servers.php.versions.install');
                Route::patch('servers/{server}/php/default-version', [ServerPhpController::class, 'setDefaultVersion'])
                    ->name('servers.php.default-version.update');

                // Processes (workers and cron jobs)
                Route::get('servers/{server}/processes', [ServerProcessController::class, 'index'])
                    ->name('servers.processes.index');
                Route::post('servers/{server}/workers', [WorkerController::class, 'store'])
                    ->name('servers.workers.store');
                Route::put('servers/{server}/workers/{worker}', [WorkerController::class, 'update'])
                    ->name('servers.workers.update');
                Route::delete('servers/{server}/workers/{worker}', [WorkerController::class, 'destroy'])
                    ->name('servers.workers.destroy');
                Route::post('servers/{server}/workers/{worker}/start', [WorkerController::class, 'start'])
                    ->name('servers.workers.start');
                Route::post('servers/{server}/workers/{worker}/stop', [WorkerController::class, 'stop'])
                    ->name('servers.workers.stop');
                Route::post('servers/{server}/workers/{worker}/restart', [WorkerController::class, 'restart'])
                    ->name('servers.workers.restart');
                Route::get('servers/{server}/workers/{worker}/logs', [WorkerController::class, 'logs'])
                    ->name('servers.workers.logs');
                Route::post('servers/{server}/cron-jobs', [CronJobController::class, 'store'])
                    ->name('servers.cron-jobs.store');
                Route::put('servers/{server}/cron-jobs/{cron_job}', [CronJobController::class, 'update'])
                    ->name('servers.cron-jobs.update');
                Route::delete('servers/{server}/cron-jobs/{cron_job}', [CronJobController::class, 'destroy'])
                    ->name('servers.cron-jobs.destroy');
                Route::patch('servers/{server}/cron-jobs/{cron_job}/disable', [CronJobController::class, 'disable'])
                    ->name('servers.cron-jobs.disable');
                Route::patch('servers/{server}/cron-jobs/{cron_job}/enable', [CronJobController::class, 'enable'])
                    ->name('servers.cron-jobs.enable');

                Route::get('servers/{server}/settings', [ServerController::class, 'settings'])
                    ->name('servers.settings');
            });
        });
});

// Deployment webhooks — no auth, no CSRF (exempt in bootstrap/app.php), secret in URL identifies the site
Route::post('webhook/{secret}', [DeploymentWebhookController::class, 'handle'])->name('deployment-webhook.handle');

// Team invitation acceptance (accessible without auth to show the page; auth required to confirm)
Route::get('invitations/{token}/accept', [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
Route::post('invitations/{token}/confirm', [TeamInvitationController::class, 'confirm'])
    ->middleware(['auth', 'verified'])
    ->name('team-invitations.confirm');

require __DIR__.'/settings.php';
