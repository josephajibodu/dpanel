<?php

use App\Enums\ConnectionStatus;
use App\Enums\Framework;
use App\Enums\NginxFileSection;
use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Enums\ServerStatus;
use App\Enums\ServiceType;
use App\Http\Resources\SiteResource;
use App\Jobs\CreateSiteJob;
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
            ->has('frameworks', count(Framework::cases()))
            ->where('frameworks.0.description', ProjectType::Laravel->description())
            ->where('frameworks.4', [
                'value' => 'react',
                'label' => 'React',
                'description' => Framework::React->description(),
                'projectType' => 'html',
                'defaultDirectory' => '/dist',
                'buildsWithNode' => true,
                'singlePageApp' => true,
            ])
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
    $snippet = SiteNginxFile::factory()->forSite($site)->create(['section' => NginxFileSection::Server]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['directory' => '/web'])
        ->assertRedirect();

    expect($site->fresh()->directory)->toBe('/web')
        ->and(SiteNginxFile::find($snippet->id))->not->toBeNull();
    Queue::assertPushed(SyncSiteNginxJob::class);
});

it('regenerates the main config when the single-page app setting changes', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $site = Site::factory()->forServer($this->server)->create(['project_type' => ProjectType::StaticHtml, 'directory' => '/']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['single_page_app' => true])
        ->assertRedirect();

    expect($site->fresh()->single_page_app)->toBeTrue();
    Queue::assertPushed(SyncSiteNginxJob::class, fn ($job) => $job->site->is($site));
});

it('creates a static site served as a single-page app', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'app.example.com',
            'directory' => '/',
            'project_type' => ProjectType::StaticHtml->value,
            'single_page_app' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('sites', ['domain' => 'app.example.com', 'single_page_app' => true]);
    Queue::assertPushed(CreateSiteJob::class);
});

it('creates a React site as a static site that builds on every deploy', function () {
    Queue::fake([CreateSiteJob::class]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'react.example.com',
            'directory' => '/dist',
            'framework' => Framework::React->value,
            'package_manager' => 'npm',
            'build_command' => 'npm run build',
            'single_page_app' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $site = Site::query()->where('domain', 'react.example.com')->sole();

    expect($site->framework)->toBe(Framework::React)
        ->and($site->project_type)->toBe(ProjectType::StaticHtml)
        ->and($site->directory)->toBe('/dist')
        ->and($site->deployScript->script)->toBe("if [ -f package-lock.json ]; then npm ci; else npm install; fi\n\nnpm run build");
    Queue::assertPushed(CreateSiteJob::class);
});

it('remembers a site created by project type alone as that project type\'s framework', function () {
    Queue::fake([CreateSiteJob::class]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'blog.example.com',
            'project_type' => ProjectType::WordPress->value,
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('sites', ['domain' => 'blog.example.com', 'project_type' => 'wordpress', 'framework' => 'wordpress']);
    Queue::assertPushed(CreateSiteJob::class);
});

it('rejects a framework it does not know', function () {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'django.example.com',
            'framework' => 'django',
        ])
        ->assertSessionHasErrors('framework');

    $this->assertDatabaseMissing('sites', ['domain' => 'django.example.com']);
});

it('creates a site for the app in one folder of its repository', function () {
    Queue::fake([CreateSiteJob::class]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'api.example.com',
            'repository' => 'acme/shop',
            'app_directory' => 'backend',
            'directory' => '/public',
            'framework' => Framework::Laravel->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('sites', ['domain' => 'api.example.com', 'app_directory' => 'backend', 'directory' => '/public']);
    Queue::assertPushed(CreateSiteJob::class);
});

it('rejects an app folder that is not one folder at the top of the repository', function (string $appDirectory) {
    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'nested.example.com',
            'app_directory' => $appDirectory,
        ])
        ->assertSessionHasErrors([
            'app_directory' => 'The app folder must be the name of one folder at the top of the repository.',
        ]);

    $this->assertDatabaseMissing('sites', ['domain' => 'nested.example.com']);
})->with(['apps/web', '..', '/backend']);

it('accepts a web directory inside a dot folder', function () {
    Queue::fake([CreateSiteJob::class]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
            'server_id' => $this->server->id,
            'domain' => 'docs.example.com',
            'directory' => '/docs/.vitepress/dist',
            'framework' => Framework::VitePress->value,
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('sites', ['domain' => 'docs.example.com', 'directory' => '/docs/.vitepress/dist']);
    Queue::assertPushed(CreateSiteJob::class);
});

it('rejects a web directory that climbs out of the site with ..', function (string $method, string $directory) {
    $site = Site::factory()->forServer($this->server)->create(['directory' => '/public']);
    $sites = "/{$this->team->slug}/servers/{$this->server->id}/sites";

    $response = $method === 'post'
        ? $this->actingAs($this->user)->post($sites, ['server_id' => $this->server->id, 'domain' => 'climb.example.com', 'directory' => $directory])
        : $this->actingAs($this->user)->put("{$sites}/{$site->id}", ['directory' => $directory]);

    $response->assertSessionHasErrors([
        'directory' => 'Directory must start with / and contain only letters, numbers, dots, underscores, and hyphens, without "..".',
    ]);
    $this->assertDatabaseMissing('sites', ['domain' => 'climb.example.com']);
    expect($site->fresh()->directory)->toBe('/public');
})->with([
    'when creating a site' => ['post', '/../../etc'],
    'when updating a site' => ['put', '/dist/..'],
]);

it('describes a site from before frameworks existed by its project type', function () {
    $site = Site::factory()->forServer($this->server)->create(['project_type' => ProjectType::Symfony, 'framework' => null]);

    $resource = (new SiteResource($site))->resolve();

    expect($resource['framework'])->toBe('symfony')
        ->and($resource['framework_label'])->toBe('Symfony');
});

it('describes a site by the framework it was created as', function () {
    $site = Site::factory()->forServer($this->server)->create(['project_type' => ProjectType::StaticHtml, 'framework' => Framework::Astro]);

    $resource = (new SiteResource($site))->resolve();

    expect($resource['framework'])->toBe('astro')
        ->and($resource['framework_label'])->toBe('Astro');
});

it('moves an isolated site to its new PHP pool with a regenerated config', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.4', false);
    $site = Site::factory()->forServer($this->server)->isolated()->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", ['php_version' => '8.4']);

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

it('switches a site to another repository and adds the server key to the new account', function () {
    Http::fake([
        'api.github.com/user/keys*' => Http::response([]),
        'gitlab.com/api/v4/user/keys*' => Http::response([]),
    ]);
    $this->server->update(['local_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIServerKey']);
    $github = SourceControlAccount::factory()->github()->create(['user_id' => $this->user->id]);
    $gitlab = SourceControlAccount::factory()->gitlab()->create(['user_id' => $this->user->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($github)->create(['repository' => 'acme/old']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'source_control_account_id' => $gitlab->id,
            'repository' => 'acme/web/new',
            'branch' => 'develop',
        ])
        ->assertSessionHasNoErrors();

    expect($site->fresh())
        ->repository->toBe('acme/web/new')
        ->source_control_account_id->toBe($gitlab->id)
        ->repository_provider->toBe(RepositoryProvider::Gitlab)
        ->branch->toBe('develop');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'gitlab.com/api/v4/user/keys'));
});

it('moves the deploy on push webhook to the new repository', function () {
    Http::fake([
        'api.github.com/repos/acme/old/hooks/4242' => Http::response(null, 204),
        'api.github.com/repos/acme/new/hooks' => Http::response(['id' => 5151], 201),
        'api.github.com/*' => Http::response([]),
    ]);
    $account = SourceControlAccount::factory()->github()->create(['user_id' => $this->user->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($account)->create([
        'repository' => 'acme/old',
        'auto_deploy' => true,
        'deploy_webhook_id' => '4242',
    ]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'source_control_account_id' => $account->id,
            'repository' => 'acme/new',
            'auto_deploy' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($site->fresh())
        ->auto_deploy->toBeTrue()
        ->deploy_webhook_id->toBe('5151');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'acme/old/hooks/4242'));
});

it('removes a site\'s repository', function () {
    $account = SourceControlAccount::factory()->github()->create(['user_id' => $this->user->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($account)->create(['repository' => 'acme/old']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'source_control_account_id' => null,
            'repository' => null,
        ])
        ->assertSessionHasNoErrors();

    expect($site->fresh())
        ->repository->toBeNull()
        ->source_control_account_id->toBeNull()
        ->repository_provider->toBeNull();
});

it('keeps a site on a teammate\'s account, but refuses a teammate\'s other accounts', function () {
    Http::fake(['*' => Http::response([])]);
    $teammate = User::factory()->create();
    $teammateAccount = SourceControlAccount::factory()->github()->create(['user_id' => $teammate->id]);
    $otherTeammateAccount = SourceControlAccount::factory()->gitlab()->create(['user_id' => $teammate->id]);
    $site = Site::factory()->forServer($this->server)->forSourceControlAccount($teammateAccount)->create(['repository' => 'acme/app']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'source_control_account_id' => $teammateAccount->id,
            'repository' => 'acme/app',
            'branch' => 'release',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'source_control_account_id' => $otherTeammateAccount->id,
            'repository' => 'acme/other',
        ])
        ->assertSessionHasErrors('source_control_account_id');

    expect($site->fresh())
        ->branch->toBe('release')
        ->source_control_account_id->toBe($teammateAccount->id);
});

it('needs an account to set a repository', function () {
    $site = Site::factory()->forServer($this->server)->create(['source_control_account_id' => null, 'repository' => null]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'source_control_account_id' => null,
            'repository' => 'acme/app',
        ])
        ->assertSessionHasErrors('source_control_account_id');
});
