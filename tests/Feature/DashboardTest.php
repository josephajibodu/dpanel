<?php

use App\Models\Team;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('authenticated users can visit the team dashboard', function () {
    $user = User::factory()->create();
    $team = Team::factory()->forUser($user)->create();
    $user->switchTeam($team);

    $this->actingAs($user)
        ->get(route('dashboard', $team))
        ->assertOk();
});

test('legacy dashboard url redirects to the current team dashboard', function () {
    $user = User::factory()->create();
    $team = Team::factory()->forUser($user)->create();
    $user->switchTeam($team);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('dashboard', $team));
});
