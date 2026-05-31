<?php

use App\Models\SourceControlAccount;
use App\Models\SourceControlRepository;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->account = SourceControlAccount::factory()
        ->forUser($this->user)
        ->github()
        ->create();
});

function fakeGithubRepos(array $repos): void
{
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response($repos, 200),
    ]);
}

function githubRepoPayload(int $id, string $owner, string $name, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'ssh_url' => "git@github.com:{$owner}/{$name}.git",
        'html_url' => "https://github.com/{$owner}/{$name}",
        'default_branch' => 'main',
        'private' => false,
    ], $overrides);
}

it('returns cached repositories without hitting GitHub when synced_at is set', function () {
    Http::fake();

    $this->account->forceFill(['repositories_synced_at' => now()])->save();

    SourceControlRepository::factory()
        ->forAccount($this->account)
        ->create([
            'provider_repo_id' => '42',
            'name' => 'cached-repo',
            'full_name' => 'octo/cached-repo',
        ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('source-control.repositories', [
            'team' => $this->team,
            'sourceControlAccount' => $this->account,
        ]));

    $response->assertOk()->assertJson([
        'repositories' => [
            ['id' => 42, 'full_name' => 'octo/cached-repo'],
        ],
    ]);

    Http::assertNothingSent();
});

it('auto-syncs from GitHub when cache is empty and synced_at is null', function () {
    fakeGithubRepos([
        githubRepoPayload(101, 'octo', 'first-repo'),
        githubRepoPayload(102, 'octo', 'second-repo'),
    ]);

    expect($this->account->repositories_synced_at)->toBeNull();
    expect($this->account->repositories()->count())->toBe(0);

    $response = $this->actingAs($this->user)
        ->getJson(route('source-control.repositories', [
            'team' => $this->team,
            'sourceControlAccount' => $this->account,
        ]));

    $response->assertOk()->assertJsonCount(2, 'repositories');

    $this->account->refresh();
    expect($this->account->repositories_synced_at)->not->toBeNull();
    expect($this->account->repositories()->count())->toBe(2);
});

it('does not auto-sync when cache is empty but synced_at is already set', function () {
    Http::fake();

    $this->account->forceFill(['repositories_synced_at' => now()->subHour()])->save();

    $response = $this->actingAs($this->user)
        ->getJson(route('source-control.repositories', [
            'team' => $this->team,
            'sourceControlAccount' => $this->account,
        ]));

    $response->assertOk()->assertJsonCount(0, 'repositories');
    Http::assertNothingSent();
});

it('upserts existing rows on sync without duplicating', function () {
    $existing = SourceControlRepository::factory()
        ->forAccount($this->account)
        ->create([
            'provider_repo_id' => '101',
            'name' => 'stale-name',
            'full_name' => 'octo/stale-name',
        ]);

    fakeGithubRepos([
        githubRepoPayload(101, 'octo', 'fresh-name'),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('source-control.repositories.sync', [
            'team' => $this->team,
            'sourceControlAccount' => $this->account,
        ]));

    $response->assertOk();

    expect($this->account->repositories()->count())->toBe(1);
    expect($existing->fresh()->name)->toBe('fresh-name');
});

it('deletes rows on sync that are no longer returned by GitHub', function () {
    SourceControlRepository::factory()
        ->forAccount($this->account)
        ->create(['provider_repo_id' => '201', 'full_name' => 'octo/gone']);

    SourceControlRepository::factory()
        ->forAccount($this->account)
        ->create(['provider_repo_id' => '202', 'full_name' => 'octo/still-here']);

    fakeGithubRepos([
        githubRepoPayload(202, 'octo', 'still-here'),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('source-control.repositories.sync', [
            'team' => $this->team,
            'sourceControlAccount' => $this->account,
        ]))
        ->assertOk();

    expect($this->account->repositories()->pluck('provider_repo_id')->all())->toBe(['202']);
});

it('does not wipe cached rows when GitHub responds with an error', function () {
    SourceControlRepository::factory()
        ->forAccount($this->account)
        ->create(['provider_repo_id' => '301', 'full_name' => 'octo/keep-me']);

    $this->account->forceFill(['repositories_synced_at' => now()->subHour()])->save();
    $originalSyncedAt = $this->account->fresh()->repositories_synced_at;

    Http::fake([
        'https://api.github.com/user/repos*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('source-control.repositories.sync', [
            'team' => $this->team,
            'sourceControlAccount' => $this->account,
        ]))
        ->assertOk();

    expect($this->account->repositories()->pluck('provider_repo_id')->all())->toBe(['301']);
    expect($this->account->fresh()->repositories_synced_at->equalTo($originalSyncedAt))->toBeTrue();
});

it('denies sync access for another users source control account', function () {
    fakeGithubRepos([]);

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->forUser($otherUser)->create();
    $otherUser->switchTeam($otherTeam);

    $this->actingAs($otherUser)
        ->postJson(route('source-control.repositories.sync', [
            'team' => $otherTeam,
            'sourceControlAccount' => $this->account,
        ]))
        ->assertForbidden();
});

it('denies guest access to the sync endpoint', function () {
    $this->postJson(route('source-control.repositories.sync', [
        'team' => $this->team,
        'sourceControlAccount' => $this->account,
    ]))->assertUnauthorized();
});
