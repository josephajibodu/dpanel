<?php

use App\Enums\Plan;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shares what a Hobby team leaves out once its one server is used', function () {
    $user = User::factory()->onPlan(Plan::Hobby)->create();
    $team = Team::factory()->forUser($user)->create();
    $user->switchTeam($team);
    Server::factory()->forTeam($team)->create();

    $this->actingAs($user)
        ->get("/{$team->slug}/servers")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.label', 'Hobby')
            ->where('plan.serverLimitMessage', 'The Hobby plan includes 1 server. Upgrade to Pro to add more.')
            ->where('plan.notIncluded', [
                'team_members' => 'Inviting team members needs the Team plan.',
                'extra_teams' => 'Creating more teams needs the Team plan.',
                'database_backups' => 'Backing up databases needs the Pro or Team plan.',
                'monitoring' => 'Uptime and certificate monitoring needs the Pro or Team plan.',
            ])
            ->where('auth.teamLimitMessage', 'Creating more teams needs the Team plan.')
        );
});

it('shares no limits on the Team plan', function () {
    $user = User::factory()->onPlan(Plan::Team)->create();
    $team = Team::factory()->forUser($user)->create();
    $user->switchTeam($team);
    Server::factory()->count(2)->forTeam($team)->create();

    $this->actingAs($user)
        ->get("/{$team->slug}/servers")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.label', 'Team')
            ->where('plan.serverLimitMessage', null)
            ->where('plan.notIncluded', [])
            ->where('auth.teamLimitMessage', null)
        );
});

it('shares the team owner\'s plan for the team and the user\'s own plan for creating teams', function () {
    $owner = User::factory()->onPlan(Plan::Team)->create();
    $team = Team::factory()->forUser($owner)->create();
    $member = User::factory()->onPlan(Plan::Hobby)->adminOf($team)->create();
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get("/{$team->slug}/servers")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.label', 'Team')
            ->where('plan.notIncluded', [])
            ->where('auth.teamLimitMessage', 'Creating more teams needs the Team plan.')
        );
});
