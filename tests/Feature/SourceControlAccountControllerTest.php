<?php

use App\Models\SourceControlAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
