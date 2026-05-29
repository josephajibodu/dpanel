<?php

use App\Models\SourceControlAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns repositories for the authenticated users source control account', function () {
    $account = SourceControlAccount::factory()
        ->forUser($this->user)
        ->github()
        ->create();

    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'id' => 123,
                'name' => 'my-repo',
                'full_name' => 'user/my-repo',
                'ssh_url' => 'git@github.com:user/my-repo.git',
                'html_url' => 'https://github.com/user/my-repo',
                'default_branch' => 'main',
                'private' => false,
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/source-control/{$account->id}/repositories");

    $response->assertOk()
        ->assertJsonStructure([
            'repositories' => [
                [
                    'id',
                    'name',
                    'full_name',
                    'ssh_url',
                    'html_url',
                    'default_branch',
                    'private',
                ],
            ],
        ])
        ->assertJson([
            'repositories' => [
                [
                    'id' => 123,
                    'name' => 'my-repo',
                    'full_name' => 'user/my-repo',
                ],
            ],
        ]);
});

it('denies guest access to repositories endpoint', function () {
    $account = SourceControlAccount::factory()
        ->forUser($this->user)
        ->create();

    $response = $this->getJson("/source-control/{$account->id}/repositories");

    $response->assertUnauthorized();
});

it('denies access to repositories for another users source control account', function () {
    $account = SourceControlAccount::factory()
        ->forUser($this->user)
        ->create();

    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->getJson("/source-control/{$account->id}/repositories");

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// OAuth popup mode
// ---------------------------------------------------------------------------

it('persists the popup flag in session when the redirect endpoint receives popup=1', function () {
    $driver = Mockery::mock(GithubProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://github.test/oauth'));

    Socialite::shouldReceive('driver')->with('github')->andReturn($driver);

    $this->actingAs($this->user)
        ->get('/auth/github/redirect?popup=1&redirect=/team/servers/1/sites/create');

    expect(session('source_control_popup'))->toBeTrue()
        ->and(session('source_control_redirect'))->toBe('/team/servers/1/sites/create');
});

it('leaves the popup flag false when the redirect endpoint is called without popup', function () {
    $driver = Mockery::mock(GithubProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://github.test/oauth'));

    Socialite::shouldReceive('driver')->with('github')->andReturn($driver);

    $this->actingAs($this->user)
        ->get('/auth/github/redirect');

    expect(session('source_control_popup'))->toBeFalse();
});

it('returns a popup-close HTML response when the OAuth callback completes in popup mode', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = '12345';
    $socialiteUser->nickname = 'octocat';
    $socialiteUser->name = 'The Octocat';
    $socialiteUser->email = 'octocat@example.test';
    $socialiteUser->avatar = 'https://avatars.example/octocat.png';
    $socialiteUser->token = 'gho_test';
    $socialiteUser->refreshToken = null;
    $socialiteUser->expiresIn = null;

    $driver = Mockery::mock(GithubProvider::class);
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($driver);

    $response = $this->actingAs($this->user)
        ->withSession([
            'source_control_popup' => true,
            'source_control_redirect' => '/somewhere',
        ])
        ->get('/auth/github/callback');

    $response->assertOk();
    expect($response->getContent())->toContain('window.close()')
        ->and($response->getContent())->toContain('flitops:source-control')
        ->and($response->getContent())->toContain('connected');

    expect($this->user->sourceControlAccounts()->where('provider_user_id', '12345')->exists())->toBeTrue();
});
