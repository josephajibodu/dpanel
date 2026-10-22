<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRoleKey;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What each built-in role must NOT be able to do. Written out by hand rather
 * than read from TeamRoleKey, so a change to a role's permissions has to be
 * made in both places on purpose.
 */
const DENIED_PERMISSIONS = [
    'owner' => [],
    'admin' => [],
    'developer' => [
        TeamPermission::RestoreBackups,
        TeamPermission::ManageServers,
        TeamPermission::ManageProviders,
    ],
    'viewer' => [
        TeamPermission::Deploy,
        TeamPermission::ManageSites,
        TeamPermission::ManageProcesses,
        TeamPermission::ManageDatabases,
        TeamPermission::ManagePhp,
        TeamPermission::RunCommands,
        TeamPermission::ViewSecrets,
        TeamPermission::AccessServers,
        TeamPermission::RunBackups,
        TeamPermission::RestoreBackups,
        TeamPermission::ManageServers,
        TeamPermission::ManageProviders,
    ],
];

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('seeds the four built-in roles', function () {
    expect(TeamRole::query()->builtIn()->orderBy('id')->pluck('name', 'key')->all())->toBe([
        'owner' => 'Owner',
        'admin' => 'Admin',
        'developer' => 'Developer',
        'viewer' => 'Viewer',
    ]);
});

it('grants each built-in role exactly its permissions', function (TeamRoleKey $roleKey, TeamPermission $permission) {
    $member = User::factory()->memberOf($this->team, $roleKey)->create();

    $expected = ! in_array($permission, DENIED_PERMISSIONS[$roleKey->value], true);

    expect($member->hasTeamPermission($this->team, $permission))->toBe($expected);
})->with(TeamRoleKey::cases())->with(TeamPermission::cases());

it('gives the team creator every permission, even without a membership row', function (TeamPermission $permission) {
    expect($this->team->owner->hasTeamPermission($this->team, $permission))->toBeTrue();
})->with(TeamPermission::cases());

it('gives the team creator every permission even if their membership row says viewer', function () {
    $creator = $this->team->owner;
    $this->team->users()->attach($creator, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Viewer)->id]);

    expect($creator->hasTeamPermission($this->team, TeamPermission::RestoreBackups))->toBeTrue();
});

it('gives someone outside the team no permissions', function () {
    $outsider = User::factory()->create();

    expect($outsider->hasTeamPermission($this->team, TeamPermission::View))->toBeFalse();
});

it('only grants a role in the team the membership belongs to', function () {
    $otherTeam = Team::factory()->create();
    $member = User::factory()->adminOf($this->team)->viewerOf($otherTeam)->create();

    expect($member->hasTeamPermission($this->team, TeamPermission::ManageServers))->toBeTrue()
        ->and($member->hasTeamPermission($otherTeam, TeamPermission::ManageServers))->toBeFalse()
        ->and($member->hasTeamPermission($otherTeam, TeamPermission::View))->toBeTrue();
});

it('grants nothing through a team-specific role until custom permissions exist', function () {
    $customRole = TeamRole::query()->create([
        'team_id' => $this->team->id,
        'key' => 'support',
        'name' => 'Support',
    ]);
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['team_role_id' => $customRole->id]);

    expect($member->hasTeamPermission($this->team, TeamPermission::View))->toBeFalse();
});

it('looks the role up once however many permissions are checked', function () {
    $member = User::factory()->developerOf($this->team)->create();

    DB::enableQueryLog();

    foreach (TeamPermission::cases() as $permission) {
        $member->hasTeamPermission($this->team, $permission);
    }

    expect(DB::getQueryLog())->toHaveCount(2);
});

it('moves old owner and member rows onto the Owner and Developer roles', function () {
    $migration = require database_path('migrations/2026_10_22_125319_create_team_roles_table.php');
    $migration->down();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    DB::table('team_user')->insert([
        ['team_id' => $this->team->id, 'user_id' => $owner->id, 'role' => 'owner'],
        ['team_id' => $this->team->id, 'user_id' => $member->id, 'role' => 'member'],
    ]);
    DB::table('team_invitations')->insert([
        ['team_id' => $this->team->id, 'email' => 'a@example.com', 'role' => 'owner', 'token' => 'token-a'],
        ['team_id' => $this->team->id, 'email' => 'b@example.com', 'role' => 'member', 'token' => 'token-b'],
    ]);

    $migration->up();

    expect($owner->teamRole($this->team)->key)->toBe('owner')
        ->and($member->teamRole($this->team)->key)->toBe('developer')
        ->and($member->hasTeamPermission($this->team, TeamPermission::Deploy))->toBeTrue()
        ->and($member->hasTeamPermission($this->team, TeamPermission::RestoreBackups))->toBeFalse()
        ->and($this->team->invitations()->orderBy('email')->get()->pluck('role')->all())->toBe(['owner', 'developer']);
});
