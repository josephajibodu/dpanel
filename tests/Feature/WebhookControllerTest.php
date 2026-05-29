<?php

use App\Enums\DeploymentStatus;
use App\Enums\RepositoryProvider;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeSite(array $attributes = []): Site
{
    return Site::factory()
        ->forServer(test()->server)
        ->create(array_merge([
            'auto_deploy' => true,
            'branch' => 'main',
            'webhook_secret' => 'test-secret-32-chars-long-12345x',
            'repository' => 'acme/app',
            'repository_provider' => RepositoryProvider::Github,
        ], $attributes));
}

function githubPayload(string $branch = 'main'): array
{
    return ['ref' => "refs/heads/{$branch}"];
}

function githubHeaders(string $body, string $secret): array
{
    return [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'Content-Type' => 'application/json',
    ];
}

// ---------------------------------------------------------------------------
// 404 — unknown secret
// ---------------------------------------------------------------------------

it('returns 404 for an unknown secret', function () {
    $this->postJson('/webhook/unknown-secret')->assertNotFound();
});

// ---------------------------------------------------------------------------
// auto_deploy disabled
// ---------------------------------------------------------------------------

it('skips deployment when auto_deploy is disabled', function () {
    $site = makeSite(['auto_deploy' => false]);

    $body = json_encode(githubPayload());
    $response = $this->postJson('/webhook/'.$site->webhook_secret, githubPayload(), githubHeaders($body, $site->webhook_secret));

    $response->assertOk()->assertJson(['message' => 'Auto-deploy is disabled for this site.']);
    expect(Deployment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// GitHub — happy path
// ---------------------------------------------------------------------------

it('triggers a deployment for a valid github push to the correct branch', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Github]);

    $payload = githubPayload('main');
    $body = json_encode($payload);
    $response = $this->postJson('/webhook/'.$site->webhook_secret, $payload, githubHeaders($body, $site->webhook_secret));

    $response->assertOk()->assertJson(['message' => 'Deployment triggered.']);
    expect(Deployment::where('site_id', $site->id)->count())->toBe(1);
    expect(Deployment::first()->triggered_by)->toBe('webhook');
    Queue::assertPushed(DeploySiteJob::class);
});

// ---------------------------------------------------------------------------
// GitHub — signature validation
// ---------------------------------------------------------------------------

it('rejects a github push with an invalid signature', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Github]);

    $payload = githubPayload();
    $this->postJson('/webhook/'.$site->webhook_secret, $payload, [
        'X-Hub-Signature-256' => 'sha256=invalidsignature',
        'Content-Type' => 'application/json',
    ])->assertForbidden();

    expect(Deployment::count())->toBe(0);
});

it('rejects a github push with no signature header', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Github]);

    $this->postJson('/webhook/'.$site->webhook_secret, githubPayload())
        ->assertForbidden();

    expect(Deployment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// GitHub — branch mismatch
// ---------------------------------------------------------------------------

it('skips deployment when pushed branch does not match the site branch', function () {
    $site = makeSite([
        'branch' => 'main',
        'repository_provider' => RepositoryProvider::Github,
    ]);

    $payload = githubPayload('develop');
    $body = json_encode($payload);
    $response = $this->postJson('/webhook/'.$site->webhook_secret, $payload, githubHeaders($body, $site->webhook_secret));

    $response->assertOk()->assertJson(['message' => 'Branch does not match. Deployment skipped.']);
    expect(Deployment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// GitLab — happy path & signature
// ---------------------------------------------------------------------------

it('triggers a deployment for a valid gitlab push', function () {
    $secret = Str::random(32);
    $site = makeSite([
        'webhook_secret' => $secret,
        'repository_provider' => RepositoryProvider::Gitlab,
    ]);

    $payload = githubPayload('main'); // GitLab uses the same ref format
    $this->postJson('/webhook/'.$secret, $payload, [
        'X-Gitlab-Token' => $secret,
        'Content-Type' => 'application/json',
    ])->assertOk()->assertJson(['message' => 'Deployment triggered.']);

    expect(Deployment::count())->toBe(1);
    Queue::assertPushed(DeploySiteJob::class);
});

it('rejects a gitlab push with an invalid token', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Gitlab]);

    $this->postJson('/webhook/'.$site->webhook_secret, githubPayload(), [
        'X-Gitlab-Token' => 'wrong-token',
        'Content-Type' => 'application/json',
    ])->assertForbidden();

    expect(Deployment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Bitbucket — no signature required
// ---------------------------------------------------------------------------

it('triggers a deployment for a valid bitbucket push', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Bitbucket]);

    $payload = ['push' => ['changes' => [['new' => ['name' => 'main']]]]];
    $this->postJson('/webhook/'.$site->webhook_secret, $payload)
        ->assertOk()->assertJson(['message' => 'Deployment triggered.']);

    expect(Deployment::count())->toBe(1);
    Queue::assertPushed(DeploySiteJob::class);
});

it('skips deployment for a bitbucket push to a different branch', function () {
    $site = makeSite([
        'branch' => 'main',
        'repository_provider' => RepositoryProvider::Bitbucket,
    ]);

    $payload = ['push' => ['changes' => [['new' => ['name' => 'feature-x']]]]];
    $this->postJson('/webhook/'.$site->webhook_secret, $payload)
        ->assertOk()->assertJson(['message' => 'Branch does not match. Deployment skipped.']);

    expect(Deployment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Custom / no provider — no signature required
// ---------------------------------------------------------------------------

it('triggers a deployment for a custom provider with no signature check', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Custom]);

    $this->postJson('/webhook/'.$site->webhook_secret, [])
        ->assertOk()->assertJson(['message' => 'Deployment triggered.']);

    expect(Deployment::count())->toBe(1);
    Queue::assertPushed(DeploySiteJob::class);
});

// ---------------------------------------------------------------------------
// Concurrent deployments
// ---------------------------------------------------------------------------

it('returns a message when a deployment is already in progress', function () {
    $site = makeSite(['repository_provider' => RepositoryProvider::Github]);

    // Create an in-progress deployment
    $site->deployments()->create([
        'status' => DeploymentStatus::Running,
        'triggered_by' => 'manual',
    ]);

    $payload = githubPayload('main');
    $body = json_encode($payload);
    $response = $this->postJson('/webhook/'.$site->webhook_secret, $payload, githubHeaders($body, $site->webhook_secret));

    $response->assertOk()->assertJson(['message' => 'A deployment is already in progress for this site.']);
    expect(Deployment::count())->toBe(1); // No new deployment created
});
