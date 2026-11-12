<?php

use App\Enums\Plan;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('billing shows the user\'s own plan and counts only the servers in teams they own', function () {
    $joinedTeam = Team::factory()->forUser(User::factory()->onPlan(Plan::Team)->create())->create();
    Server::factory()->count(2)->forTeam($joinedTeam)->create();

    $user = User::factory()->onPlan(Plan::Hobby)->adminOf($joinedTeam)->create();
    Server::factory()->forTeam(Team::factory()->forUser($user)->create())->create();
    $user->switchTeam($joinedTeam);

    $this->actingAs($user)
        ->get(route('billing.show'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/billing')
            ->where('currentPlan', 'hobby')
            ->where('serverCount', 1)
            ->where('serverLimit', 1)
        );
});

test('billing sends guests to the login page', function () {
    $this->get(route('billing.show'))->assertRedirect(route('login'));
});
