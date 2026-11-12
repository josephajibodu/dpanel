<?php

use App\Enums\Plan;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shares what a Hobby team leaves out once its one server is used, with the plan that unlocks each', function () {
    $user = User::factory()->onPlan(Plan::Hobby)->create();
    $team = Team::factory()->forUser($user)->create();
    $user->switchTeam($team);
    Server::factory()->forTeam($team)->create();

    $this->actingAs($user)
        ->get("/{$team->slug}/servers")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.label', 'Hobby')
            ->where('plan.serverLimit', ['message' => 'The Hobby plan includes 1 server. Upgrade to Pro to add more.', 'plan' => 'Pro'])
            ->where('plan.notIncluded', [
                'team_members' => ['message' => 'Inviting team members needs the Team plan.', 'plan' => 'Team'],
                'extra_teams' => ['message' => 'Creating more teams needs the Team plan.', 'plan' => 'Team'],
                'database_backups' => ['message' => 'Backing up databases needs the Pro or Team plan.', 'plan' => 'Pro'],
                'monitoring' => ['message' => 'Uptime and certificate monitoring needs the Pro or Team plan.', 'plan' => 'Pro'],
            ])
            ->where('auth.teamLimit', ['message' => 'Creating more teams needs the Team plan.', 'plan' => 'Team'])
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
            ->where('plan.serverLimit', null)
            ->where('plan.notIncluded', [])
            ->where('auth.teamLimit', null)
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
            ->where('auth.teamLimit.plan', 'Team')
        );
});
