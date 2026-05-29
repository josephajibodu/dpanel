<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

it('returns a freshly generated server name as json', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/{$this->team->slug}/servers/generate-name");

    $response->assertOk()->assertJsonStructure(['name']);

    expect($response->json('name'))->toMatch('/^[a-zA-Z0-9-]+$/');
});

it('requires authentication to generate a server name', function () {
    $this->get("/{$this->team->slug}/servers/generate-name")
        ->assertRedirect('/login');
});

it('seeds the create page with a generated server name', function () {
    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/create");

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('servers/create')
        ->where('generatedName', fn ($name) => is_string($name) && preg_match('/^[a-zA-Z0-9-]+$/', $name) === 1)
    );
});
