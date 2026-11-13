<?php

use App\Actions\Worker\CreateWorker;
use App\Actions\Worker\DestroyWorker;
use App\Enums\NginxFileSection;
use App\Enums\OctaneServer;
use App\Enums\Plan;
use App\Enums\ProjectType;
use App\Enums\ServiceType;
use App\Enums\SiteStatus;
use App\Jobs\DestroyWorkerJob;
use App\Jobs\SyncEnvironmentJob;
use App\Jobs\SyncSiteOctaneJob;
use App\Jobs\UpdateWorkerJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\SiteOctaneFailed;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->onPlan(Plan::Pro)->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create();
});

function octaneSite(Server $server, array $attributes = []): Site
{
    return Site::factory()->create([
        'server_id' => $server->id,
        'project_type' => ProjectType::Laravel,
        'status' => SiteStatus::Deployed,
        'php_version' => '8.3',
        ...$attributes,
    ]);
}

/**
 * A site with Octane switched on and the process the switch adds, as the
 * background job finds them.
 */
function siteRunningOctane(Server $server): Site
{
    $site = octaneSite($server, ['octane_server' => OctaneServer::FrankenPhp, 'octane_port' => 8100]);

    Worker::factory()->forSite($site)->create([
        'name' => 'Octane',
        'command' => $site->octaneCommand(),
        'user' => $site->systemUser(),
        'restart_on_deploy' => true,
        'runs_octane' => true,
        'status' => 'pending',
    ]);

    return $site;
}

function runSyncSiteOctaneJob(Site $site): void
{
    (new SyncSiteOctaneJob($site))->handle(app(SshService::class), app(CreateWorker::class), app(DestroyWorker::class));
}

function mainConfigOf(Site $site): string
{
    return (string) $site->nginxFiles()->where('section', NginxFileSection::Main->value)->value('content');
}

describe('switching Octane on and off', function () {
    it('reserves the first Octane port and adds a process that restarts on deploy', function () {
        Queue::fake([SyncSiteOctaneJob::class]);
        $site = octaneSite($this->server);

        $this->actingAs($this->user)
            ->put(route('servers.sites.update', [$this->team, $this->server, $site]), ['octane_server' => 'frankenphp'])
            ->assertRedirect(route('servers.sites.show', [$this->team, $this->server, $site]))
            ->assertSessionHasNoErrors();

        $site->refresh();
        expect($site->octane_server)->toBe(OctaneServer::FrankenPhp)
            ->and($site->octane_port)->toBe(8100);

        $this->assertDatabaseHas('workers', [
            'site_id' => $site->id,
            'runs_octane' => true,
            'restart_on_deploy' => true,
            'command' => 'php8.3 artisan octane:start --server=frankenphp --host=127.0.0.1 --port=8100',
        ]);
        Queue::assertPushed(SyncSiteOctaneJob::class, fn (SyncSiteOctaneJob $job) => $job->site->is($site));
    });

    it('gives a second Octane site on the same server the next port', function () {
        Queue::fake([SyncSiteOctaneJob::class]);
        octaneSite($this->server, ['octane_server' => OctaneServer::FrankenPhp, 'octane_port' => 8100]);
        $site = octaneSite($this->server);

        $this->actingAs($this->user)
            ->put(route('servers.sites.update', [$this->team, $this->server, $site]), ['octane_server' => 'frankenphp']);

        expect($site->fresh()->octane_port)->toBe(8101);
    });

    it('refuses to switch Octane on, saving nothing, when the site cannot run it', function (Closure $arrange, string $message) {
        Queue::fake([SyncSiteOctaneJob::class]);
        $site = $arrange($this);

        $this->actingAs($this->user)
            ->put(route('servers.sites.update', [$this->team, $this->server, $site]), ['octane_server' => 'frankenphp'])
            ->assertSessionHasErrors(['octane_server' => $message]);

        expect($site->fresh()->octane_server)->toBeNull()
            ->and($site->fresh()->octane_port)->toBeNull();
        $this->assertDatabaseMissing('workers', ['site_id' => $site->id]);
        Queue::assertNotPushed(SyncSiteOctaneJob::class);
    })->with([
        'Hobby plan' => [function ($test) {
            $test->user->forceFill(['plan' => Plan::Hobby])->save();

            return octaneSite($test->server);
        }, 'Running a site on Laravel Octane needs the Pro or Team plan.'],
        'not a Laravel site' => [fn ($test) => octaneSite($test->server, ['project_type' => ProjectType::Symfony]), 'Octane is only available for Laravel sites.'],
        'never deployed' => [fn ($test) => octaneSite($test->server, ['status' => SiteStatus::Provisioned]), 'Deploy the site once before switching Octane on.'],
    ]);

    it('does nothing when Octane is already set the way the form asks', function () {
        Queue::fake([SyncSiteOctaneJob::class]);
        $site = octaneSite($this->server, ['octane_server' => OctaneServer::FrankenPhp, 'octane_port' => 8100]);

        $this->actingAs($this->user)
            ->put(route('servers.sites.update', [$this->team, $this->server, $site]), ['octane_server' => 'frankenphp']);

        Queue::assertNotPushed(SyncSiteOctaneJob::class);
    });

    it('switches Octane off and asks the server to follow', function () {
        Queue::fake([SyncSiteOctaneJob::class]);
        $site = siteRunningOctane($this->server);

        $this->actingAs($this->user)
            ->put(route('servers.sites.update', [$this->team, $this->server, $site]), ['octane_server' => null])
            ->assertSessionHasNoErrors();

        expect($site->fresh()->octane_server)->toBeNull();
        Queue::assertPushed(SyncSiteOctaneJob::class, fn (SyncSiteOctaneJob $job) => $job->site->is($site));
    });

    it('restarts Octane with the new PHP program when the PHP version changes', function () {
        Queue::fake();
        $this->server->createService(ServiceType::Php, '8.3', true);
        $this->server->createService(ServiceType::Php, '8.4', false);
        $site = siteRunningOctane($this->server);

        $this->actingAs($this->user)
            ->put(route('servers.sites.update', [$this->team, $this->server, $site]), ['php_version' => '8.4']);

        Queue::assertPushed(SyncSiteOctaneJob::class, fn (SyncSiteOctaneJob $job) => $job->site->is($site));
    });
});

describe('the Octane process on the Processes page', function () {
    it('cannot be edited by hand', function () {
        Queue::fake([UpdateWorkerJob::class]);
        $site = siteRunningOctane($this->server);
        $worker = $site->octaneWorker;

        $this->actingAs($this->user)
            ->put(route('servers.workers.update', [$this->team, $this->server, $worker]), [
                'name' => 'Renamed',
                'command' => 'php8.3 artisan queue:work',
                'site_id' => $site->id,
                'user' => $site->systemUser(),
                'numprocs' => 1,
            ])
            ->assertSessionHas('error', "This process is managed by the site's Octane setting. Switch Octane off in the site's settings to remove it.");

        expect($worker->fresh()->name)->toBe('Octane');
        Queue::assertNotPushed(UpdateWorkerJob::class);
    });

    it('cannot be deleted by hand', function () {
        Queue::fake([DestroyWorkerJob::class]);
        $worker = siteRunningOctane($this->server)->octaneWorker;

        $this->actingAs($this->user)
            ->delete(route('servers.workers.destroy', [$this->team, $this->server, $worker]))
            ->assertSessionHas('error', "This process is managed by the site's Octane setting. Switch Octane off in the site's settings to remove it.");

        expect($worker->fresh()->status)->toBe('pending');
        Queue::assertNotPushed(DestroyWorkerJob::class);
    });
});

describe('SyncSiteOctaneJob', function () {
    it('starts the Octane process and points the web server at it', function () {
        $commands = [];
        $uploads = [];
        fakeHostSsh($commands, $uploads, ['vendor/laravel/octane' => 'yes']);
        $site = siteRunningOctane($this->server);

        runSyncSiteOctaneJob($site);

        expect($site->octaneWorker()->value('status'))->toBe('active')
            ->and($site->fresh()->usesOctane())->toBeTrue()
            ->and(mainConfigOf($site))->toContain('proxy_pass http://127.0.0.1:8100');
    });

    it('switches Octane back off and emails the owner when the app does not include Octane', function () {
        Notification::fake();
        $commands = [];
        $uploads = [];
        fakeHostSsh($commands, $uploads);
        $site = siteRunningOctane($this->server);

        runSyncSiteOctaneJob($site);

        $site->refresh();
        expect($site->octane_server)->toBeNull()
            ->and($site->octane_port)->toBeNull()
            ->and($site->octaneWorker()->exists())->toBeFalse();
        Notification::assertSentTo($this->user, SiteOctaneFailed::class);
    });

    it('sends the site back to PHP-FPM and removes the process when Octane is switched off', function () {
        $commands = [];
        $uploads = [];
        fakeHostSsh($commands, $uploads);
        $site = siteRunningOctane($this->server);
        $site->update(['octane_server' => null]);

        runSyncSiteOctaneJob($site);

        expect($site->octaneWorker()->exists())->toBeFalse()
            ->and($site->fresh()->octane_port)->toBeNull()
            ->and(mainConfigOf($site))->toContain('fastcgi_pass');
    });
});

it('restarts the Octane process when the environment is saved with a restart', function () {
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);
    $site = siteRunningOctane($this->server);
    $site->update(['env_content' => "APP_KEY=secret\n"]);
    $worker = $site->octaneWorker;
    $worker->update(['status' => 'active']);

    (new SyncEnvironmentJob($site, restartQueue: true))->handle(app(SshService::class));

    expect($commands)->toContain("sudo supervisorctl restart flitops-worker-{$worker->id}:*");
});
