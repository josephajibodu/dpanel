<?php

use App\Enums\ConnectionStatus;
use App\Enums\NginxFileSection;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Enums\ServiceType;
use App\Jobs\MoveSitePhpPoolJob;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNginxFile;
use App\Models\SourceControlAccount;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
});

it('edit page uses server installed PHP versions instead of hardcoded list', function () {
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.1', false);

    $site = Site::factory()->forServer($this->server)->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/edit");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('phpVersions', fn ($versions) => collect($versions)->pluck('value')->sort()->values()->all() === ['8.1', '8.3'])
        );
});

it('dispatches SyncSiteNginxJob when php_version changes and server is ready', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.2', false);

    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.2',
        ]);

    Queue::assertPushed(SyncSiteNginxJob::class, fn ($job) => $job->site->id === $site->id);
    expect($site->fresh()->php_version)->toBe('8.2');
});

it('does not dispatch SyncSiteNginxJob when php_version is unchanged', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);

    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.3',
        ]);

    Queue::assertNotPushed(SyncSiteNginxJob::class);
});

it('does not dispatch SyncSiteNginxJob when server is not ready', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Failed]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.2', false);

    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.2',
        ]);

    Queue::assertNotPushed(SyncSiteNginxJob::class);
});

it('rejects php_version not installed on the server', function () {
    $this->server->createService(ServiceType::Php, '8.3', true);
    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.1',
        ]);

    $response->assertSessionHasErrors('php_version');
});

it('shows site create page with source control accounts but without prefetched repositories', function () {
    SourceControlAccount::factory()
        ->forUser($this->user)
        ->count(2)
        ->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/create");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/create')
            ->has('server')
            ->has('projectTypes')
            ->where('projectTypes.0.description', ProjectType::Laravel->description())
            ->has('repositoryProviders')
            ->has('phpVersions')
            ->has('sourceControl')
            ->has('sourceControl.accounts')
            ->where('sourceControl.accounts.data', fn ($accounts) => count($accounts) === 2)
            ->missing('sourceControl.repositories')
        );
});

it('reports a free subdomain as available when the hostname is unused', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/sites/check-site-name", [
            'site_name' => 'brand-new-app',
        ]);

    $response->assertOk()
        ->assertJson(['available' => true]);
});

it('reports a free subdomain as taken when the hostname is already in use', function () {
    $site = Site::factory()->forServer($this->server)->create();
    SiteDomain::factory()->for($site)->create([
        'hostname' => 'taken-app.'.config('server.free_domain'),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/sites/check-site-name", [
            'site_name' => 'taken-app',
        ]);

    $response->assertOk()
        ->assertJson(['available' => false]);
});

it('rejects an invalid free subdomain name', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/{$this->team->slug}/servers/{$this->server->id}/sites/check-site-name", [
            'site_name' => 'not valid!',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('site_name');
});

it('regenerates the main nginx config when the web directory changes', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $site = Site::factory()->forServer($this->server)->create(['directory' => '/public']);
    $main = SiteNginxFile::factory()->forSite($site)->create(['section' => NginxFileSection::Main, 'path' => 'main.conf']);
    $snippet = SiteNginxFile::factory()->forSite($site)->create(['section' => NginxFileSection::Server]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['directory' => '/web'])
        ->assertRedirect();

    expect($site->fresh()->directory)->toBe('/web')
        ->and(SiteNginxFile::find($main->id))->toBeNull()
        ->and(SiteNginxFile::find($snippet->id))->not->toBeNull();
    Queue::assertPushed(SyncSiteNginxJob::class);
});

it('moves an isolated site to its new PHP pool with a regenerated config', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.4', false);
    $site = Site::factory()->forServer($this->server)->isolated()->create(['php_version' => '8.3']);
    $main = SiteNginxFile::factory()->forSite($site)->create(['section' => NginxFileSection::Main, 'path' => 'main.conf']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['php_version' => '8.4']);

    expect(SiteNginxFile::find($main->id))->toBeNull();
    Queue::assertPushed(MoveSitePhpPoolJob::class, fn ($job) => $job->previousPhpVersion === '8.3');
    Queue::assertNotPushed(SyncSiteNginxJob::class);
});

it('ignores domain and project type changes sent to the update endpoint', function () {
    $site = Site::factory()->forServer($this->server)->create(['project_type' => ProjectType::Laravel]);
    $domain = $site->domain;

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'domain' => 'other.example.com',
            'project_type' => ProjectType::StaticHtml->value,
        ]);

    expect($site->fresh())
        ->domain->toBe($domain)
        ->project_type->toBe(ProjectType::Laravel);
});

it('adds a GitHub webhook when deploy on push is turned on', function () {
    Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 4242], 201)]);

    $account = SourceControlAccount::factory()->create(['user_id' => $this->user->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($account)->create(['repository' => 'acme/app']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['auto_deploy' => true])
        ->assertSessionHasNoErrors();

    expect($site->fresh())
        ->auto_deploy->toBeTrue()
        ->deploy_webhook_id->toBe('4242');
    Http::assertSent(fn ($request) => $request['config']['url'] === route('deployment-webhook.handle', $site->webhook_secret)
        && $request['config']['secret'] === $site->webhook_secret
        && $request['events'] === ['push']);
});

it('keeps deploy on push off when GitHub refuses the webhook', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $account = SourceControlAccount::factory()->create(['user_id' => $this->user->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($account)->create(['repository' => 'acme/app']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['auto_deploy' => true])
        ->assertSessionHasErrors('auto_deploy');

    expect($site->fresh())
        ->auto_deploy->toBeFalse()
        ->deploy_webhook_id->toBeNull();
});

it('removes the GitHub webhook when deploy on push is turned off', function () {
    Http::fake(['api.github.com/repos/acme/app/hooks/4242' => Http::response(null, 204)]);

    $account = SourceControlAccount::factory()->create(['user_id' => $this->user->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($account)->create([
        'repository' => 'acme/app',
        'auto_deploy' => true,
        'deploy_webhook_id' => '4242',
    ]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['auto_deploy' => false]);

    expect($site->fresh())
        ->auto_deploy->toBeFalse()
        ->deploy_webhook_id->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

it('refuses deploy on push for a site without a connected repository', function () {
    Http::fake();

    $site = Site::factory()->forServer($this->server)->create(['source_control_account_id' => null]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['auto_deploy' => true])
        ->assertSessionHasErrors('auto_deploy');

    expect($site->fresh()->auto_deploy)->toBeFalse();
    Http::assertNothingSent();
});
