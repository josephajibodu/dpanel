<?php

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * The permission map every page receives, with only the given permissions set.
 *
 * @param  list<TeamPermission>  $granted
 * @return array<string, bool>
 */
function permissionMap(array $granted): array
{
    return collect(TeamPermission::cases())
        ->mapWithKeys(fn (TeamPermission $permission): array => [
            $permission->value => in_array($permission, $granted, true),
        ])
        ->all();
}

beforeEach(function () {
    $this->creator = User::factory()->create();
    $this->team = Team::factory()->forUser($this->creator)->create(['personal_team' => false]);
});

it('shares what each built-in role may do in the current team', function (string $role, array $granted) {
    $member = User::factory()->{$role.'Of'}($this->team)->create();
    $member->switchTeam($this->team);

    $this->actingAs($member)
        ->get("/{$this->team->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permissions', permissionMap($granted))
        );
})->with([
    'owner' => ['owner', TeamPermission::cases()],
    'admin' => ['admin', array_values(array_filter(
        TeamPermission::cases(),
        fn (TeamPermission $permission): bool => ! in_array($permission, TeamPermission::ownerOnly(), true),
    ))],
    'developer' => ['developer', [
        TeamPermission::View,
        TeamPermission::Deploy,
        TeamPermission::ManageSites,
        TeamPermission::ManageProcesses,
        TeamPermission::ManageDatabases,
        TeamPermission::ManagePhp,
        TeamPermission::RunCommands,
        TeamPermission::ViewSecrets,
        TeamPermission::AccessServers,
        TeamPermission::RunBackups,
    ]],
    'viewer' => ['viewer', [TeamPermission::View]],
]);

it('gives the team creator every permission', function () {
    $this->creator->switchTeam($this->team);

    $this->actingAs($this->creator)
        ->get("/{$this->team->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permissions', permissionMap(TeamPermission::cases()))
        );
});

it('shares permissions for the team in the URL when following a link to another team', function () {
    $viewer = User::factory()->viewerOf($this->team)->create();
    $ownTeam = Team::factory()->forUser($viewer)->create();
    $viewer->switchTeam($ownTeam);

    $this->actingAs($viewer)
        ->get("/{$this->team->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentTeam.id', $this->team->id)
            ->where('auth.permissions', permissionMap([TeamPermission::View]))
        );
});

it('shares the current team permissions on pages outside a team', function () {
    $viewer = User::factory()->viewerOf($this->team)->create();
    $viewer->switchTeam($this->team);

    $this->actingAs($viewer)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permissions', permissionMap([TeamPermission::View]))
        );
});

it('shares no permissions with guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permissions', [])
        );
});
