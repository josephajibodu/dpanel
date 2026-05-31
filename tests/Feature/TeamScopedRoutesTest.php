<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

it('redirects legacy ssh keys url to the current team', function () {
    $this->actingAs($this->user)
        ->get('/ssh-keys')
        ->assertRedirect(route('ssh-keys.index', $this->team));
});

it('redirects legacy source control url to the current team', function () {
    $this->actingAs($this->user)
        ->get('/source-control')
        ->assertRedirect(route('source-control.index', $this->team));
});

it('serves ssh keys under the team prefix', function () {
    $this->actingAs($this->user)
        ->get(route('ssh-keys.index', $this->team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ssh-keys/index'));
});

it('serves source control under the team prefix', function () {
    $this->actingAs($this->user)
        ->get(route('source-control.index', $this->team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('source-control/index'));
});
