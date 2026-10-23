<?php

use App\Enums\TeamRoleKey;
use App\Jobs\RevokeSshKeyJob;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamRole;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The roles each kind of actor may hand out, and so also the roles of the
 * members they may change, remove or cancel an invitation for. Written out by
 * hand so the rules have to be changed here on purpose.
 */
const GRANTABLE_ROLES = [
    'creator' => ['owner', 'admin', 'developer', 'viewer'],
    'owner' => ['owner', 'admin', 'developer', 'viewer'],
    'admin' => ['admin', 'developer', 'viewer'],
    'developer' => [],
    'viewer' => [],
];

const ACTORS = ['creator', 'owner', 'admin', 'developer', 'viewer'];

/**
 * The team creator, or a new member holding the given role.
 */
function teamActor(Team $team, string $actor): User
{
    if ($actor === 'creator') {
        return $team->owner;
    }

    return User::factory()->memberOf($team, TeamRoleKey::from($actor))->create();
}

beforeEach(function () {
    Queue::fake();
    Notification::fake();

    $creator = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $creator->id]);
    $this->team->users()->attach($creator, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Owner)->id]);
});

describe('inviting', function () {
    it('lets each role invite only the roles it may grant', function (string $actor, TeamRoleKey $role) {
        $response = $this->actingAs(teamActor($this->team, $actor))
            ->post(route('team-invitations.store', $this->team), ['email' => 'new@example.com', 'role' => $role->value]);

        if (in_array($role->value, GRANTABLE_ROLES[$actor], true)) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            expect(TeamInvitation::query()->sole()->role)->toBe($role->value);
            Notification::assertSentOnDemand(TeamInvitationNotification::class);
        } else {
            $response->assertForbidden();
            expect(TeamInvitation::query()->count())->toBe(0);
        }
    })->with(ACTORS)->with(TeamRoleKey::cases());

    it('applies the same rules on the members route', function () {
        $admin = teamActor($this->team, 'admin');

        $this->actingAs($admin)
            ->post(route('team-members.store', $this->team), ['email' => 'boss@example.com', 'role' => 'owner'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('team-members.store', $this->team), ['email' => 'dev@example.com', 'role' => 'developer'])
            ->assertSessionHasNoErrors();

        expect(TeamInvitation::query()->pluck('email')->all())->toBe(['dev@example.com']);
    });

    it('rejects a role that is not one of the built-in roles', function (mixed $role) {
        $this->actingAs($this->team->owner)
            ->post(route('team-invitations.store', $this->team), ['email' => 'new@example.com', 'role' => $role])
            ->assertSessionHasErrors('role');

        expect(TeamInvitation::query()->count())->toBe(0);
    })->with([
        'old member value' => 'member',
        'unknown key' => 'superadmin',
        'empty' => '',
        'array' => [['owner']],
    ]);
});

describe('changing a role', function () {
    it('lets each role change only members it outranks, and only to roles it may grant', function (string $actor, TeamRoleKey $currentRole, TeamRoleKey $newRole) {
        $member = User::factory()->memberOf($this->team, $currentRole)->create();

        $response = $this->actingAs(teamActor($this->team, $actor))
            ->patch(route('team-members.update', [$this->team, $member]), ['role' => $newRole->value]);

        $allowed = in_array($currentRole->value, GRANTABLE_ROLES[$actor], true)
            && in_array($newRole->value, GRANTABLE_ROLES[$actor], true);

        if ($allowed) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            expect($member->fresh()->teamRole($this->team)->key)->toBe($newRole->value);
        } else {
            $response->assertForbidden();
            expect($member->fresh()->teamRole($this->team)->key)->toBe($currentRole->value);
        }
    })->with(ACTORS)->with(TeamRoleKey::cases())->with(TeamRoleKey::cases());

    it('lets an owner make an admin an owner', function () {
        $admin = teamActor($this->team, 'admin');

        $this->actingAs(teamActor($this->team, 'owner'))
            ->patch(route('team-members.update', [$this->team, $admin]), ['role' => 'owner'])
            ->assertSessionHasNoErrors();

        expect($admin->fresh()->teamRole($this->team)->key)->toBe('owner');
    });

    it('does not let anyone change their own role', function (string $actor, TeamRoleKey $newRole) {
        $user = teamActor($this->team, $actor);

        $this->actingAs($user)
            ->patch(route('team-members.update', [$this->team, $user]), ['role' => $newRole->value])
            ->assertForbidden();

        expect($user->fresh()->teamRole($this->team)->key)->toBe($actor === 'creator' ? 'owner' : $actor);
    })->with(ACTORS)->with(TeamRoleKey::cases());

    it('never lets the team creator be demoted', function (TeamRoleKey $newRole) {
        $this->actingAs(teamActor($this->team, 'owner'))
            ->patch(route('team-members.update', [$this->team, $this->team->owner]), ['role' => $newRole->value])
            ->assertForbidden();

        expect($this->team->owner->fresh()->teamRole($this->team)->key)->toBe('owner');
    })->with(TeamRoleKey::cases());

    it('rejects a role that is not one of the built-in roles', function (mixed $role) {
        $member = User::factory()->developerOf($this->team)->create();

        $this->actingAs($this->team->owner)
            ->patch(route('team-members.update', [$this->team, $member]), ['role' => $role])
            ->assertSessionHasErrors('role');

        expect($member->fresh()->teamRole($this->team)->key)->toBe('developer');
    })->with([
        'old member value' => 'member',
        'unknown key' => 'superadmin',
        'empty' => '',
        'array' => [['owner']],
    ]);

    it('does not touch someone who is not in the team', function () {
        $otherTeam = Team::factory()->create();
        $outsider = User::factory()->developerOf($otherTeam)->create();

        $this->actingAs($this->team->owner)
            ->patch(route('team-members.update', [$this->team, $outsider]), ['role' => 'viewer'])
            ->assertForbidden();

        expect($outsider->fresh()->teamRole($otherTeam)->key)->toBe('developer')
            ->and($outsider->fresh()->teamRole($this->team))->toBeNull();
    });

    it('does not let an owner of another team change members here', function () {
        $member = User::factory()->developerOf($this->team)->create();
        $otherTeam = Team::factory()->create();

        $this->actingAs($otherTeam->owner)
            ->patch(route('team-members.update', [$this->team, $member]), ['role' => 'viewer'])
            ->assertForbidden();
    });
});

describe('server access on a role change', function () {
    beforeEach(function () {
        $this->teamServer = Server::factory()->forTeam($this->team)->create();
        $this->otherTeamServer = Server::factory()->create();
    });

    /**
     * A member holding the given role, with one SSH key synced to a server of
     * this team and to a server of another team.
     */
    function memberWithSyncedKey(Team $team, TeamRoleKey $role, Server ...$servers): array
    {
        $member = User::factory()->memberOf($team, $role)->create();
        $sshKey = SshKey::factory()->create(['user_id' => $member->id]);

        foreach ($servers as $server) {
            $sshKey->servers()->attach($server->id, ['status' => 'synced']);
        }

        return [$member, $sshKey];
    }

    it('pulls the member keys off this team servers when they lose server access', function (TeamRoleKey $from) {
        [$member, $sshKey] = memberWithSyncedKey($this->team, $from, $this->teamServer, $this->otherTeamServer);

        $this->actingAs($this->team->owner)
            ->patch(route('team-members.update', [$this->team, $member]), ['role' => 'viewer'])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(RevokeSshKeyJob::class, 1);
        Queue::assertPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->server->is($this->teamServer) && $job->sshKeyId === $sshKey->id);

        expect($sshKey->servers()->where('servers.id', $this->teamServer->id)->first()->pivot->status)->toBe('revoking')
            ->and($sshKey->servers()->where('servers.id', $this->otherTeamServer->id)->first()->pivot->status)->toBe('synced');
    })->with([TeamRoleKey::Developer, TeamRoleKey::Admin, TeamRoleKey::Owner]);

    it('leaves the member keys alone when they keep or gain admin server access', function (TeamRoleKey $from, TeamRoleKey $to) {
        [$member, $sshKey] = memberWithSyncedKey($this->team, $from, $this->teamServer);

        $this->actingAs($this->team->owner)
            ->patch(route('team-members.update', [$this->team, $member]), ['role' => $to->value])
            ->assertSessionHasNoErrors();

        Queue::assertNotPushed(RevokeSshKeyJob::class);
        expect($sshKey->servers()->first()->pivot->status)->toBe('synced');
    })->with([
        'developer to admin' => [TeamRoleKey::Developer, TeamRoleKey::Admin],
        'admin to owner' => [TeamRoleKey::Admin, TeamRoleKey::Owner],
        'owner to admin' => [TeamRoleKey::Owner, TeamRoleKey::Admin],
    ]);
});

describe('removing', function () {
    it('lets each role remove only members it outranks', function (string $actor, TeamRoleKey $memberRole) {
        $member = User::factory()->memberOf($this->team, $memberRole)->create();

        $response = $this->actingAs(teamActor($this->team, $actor))
            ->delete(route('team-members.destroy', [$this->team, $member]));

        if (in_array($memberRole->value, GRANTABLE_ROLES[$actor], true)) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            expect($member->fresh()->belongsToTeam($this->team))->toBeFalse();
        } else {
            $response->assertForbidden();
            expect($member->fresh()->belongsToTeam($this->team))->toBeTrue();
        }
    })->with(ACTORS)->with(TeamRoleKey::cases());

    it('never lets the team creator be removed', function () {
        $this->actingAs(teamActor($this->team, 'owner'))
            ->delete(route('team-members.destroy', [$this->team, $this->team->owner]))
            ->assertForbidden();

        expect($this->team->hasUser($this->team->owner))->toBeTrue()
            ->and($this->team->users()->whereKey($this->team->user_id)->exists())->toBeTrue();
    });

    it('does not let anyone remove themselves here', function (string $actor) {
        $user = teamActor($this->team, $actor);

        $this->actingAs($user)
            ->delete(route('team-members.destroy', [$this->team, $user]))
            ->assertForbidden();

        expect($this->team->users()->whereKey($user->id)->exists())->toBeTrue();
    })->with(ACTORS);
});

describe('cancelling an invitation', function () {
    it('lets each role cancel only invitations for roles it may grant', function (string $actor, TeamRoleKey $invitedRole) {
        $invitation = $this->team->invitations()->create([
            'email' => 'invitee@example.com',
            'team_role_id' => TeamRole::builtIn($invitedRole)->id,
            'token' => 'cancel-token',
        ]);

        $response = $this->actingAs(teamActor($this->team, $actor))
            ->delete(route('team-invitations.destroy', [$this->team, $invitation]));

        if (in_array($invitedRole->value, GRANTABLE_ROLES[$actor], true)) {
            $response->assertRedirect();
            expect($invitation->fresh())->toBeNull();
        } else {
            $response->assertForbidden();
            expect($invitation->fresh())->not->toBeNull();
        }
    })->with(ACTORS)->with(TeamRoleKey::cases());

    it('does not cancel another team invitation through this team', function () {
        $otherTeam = Team::factory()->create();
        $invitation = $otherTeam->invitations()->create([
            'email' => 'invitee@example.com',
            'team_role_id' => TeamRole::builtIn(TeamRoleKey::Developer)->id,
            'token' => 'other-team-token',
        ]);

        $this->actingAs($this->team->owner)
            ->delete(route('team-invitations.destroy', [$this->team, $invitation]))
            ->assertForbidden();

        expect($invitation->fresh())->not->toBeNull();
    });
});

describe('team settings', function () {
    it('lets owners and admins rename the team', function (string $actor, bool $allowed) {
        $response = $this->actingAs(teamActor($this->team, $actor))
            ->put(route('teams.update', $this->team), ['name' => 'Renamed']);

        if ($allowed) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            expect($this->team->fresh()->name)->toBe('Renamed');
        } else {
            $response->assertForbidden();
            expect($this->team->fresh()->name)->not->toBe('Renamed');
        }
    })->with([
        'creator' => ['creator', true],
        'owner' => ['owner', true],
        'admin' => ['admin', true],
        'developer' => ['developer', false],
        'viewer' => ['viewer', false],
    ]);

    it('lets only owners delete the team', function (string $actor, bool $allowed) {
        $user = teamActor($this->team, $actor);
        $personalTeam = Team::factory()->forUser($user)->create();
        $user->switchTeam($this->team);

        $response = $this->actingAs($user)
            ->delete(route('teams.destroy', $this->team), ['name' => $this->team->name]);

        if ($allowed) {
            $response->assertRedirect(route('servers.index', $personalTeam));
            expect($this->team->fresh())->toBeNull();
        } else {
            $response->assertForbidden();
            expect($this->team->fresh())->not->toBeNull();
        }
    })->with([
        'creator' => ['creator', true],
        'owner' => ['owner', true],
        'admin' => ['admin', false],
        'developer' => ['developer', false],
        'viewer' => ['viewer', false],
    ]);

    it('never deletes a personal team', function () {
        $personalTeam = Team::factory()->personal()->create();

        $this->actingAs($personalTeam->owner)
            ->delete(route('teams.destroy', $personalTeam), ['name' => $personalTeam->name])
            ->assertForbidden();

        expect($personalTeam->fresh())->not->toBeNull();
    });

    it('only deletes the team when the request repeats its exact name', function (array $payload, string $message) {
        $this->team->update(['name' => 'Acme Ops']);

        $this->actingAs($this->team->owner)
            ->delete(route('teams.destroy', $this->team), $payload)
            ->assertSessionHasErrors(['name' => $message]);

        expect($this->team->fresh())->not->toBeNull();
    })->with([
        'missing' => [[], "Type the team's name to confirm you want to delete it."],
        'wrong name' => [['name' => 'Other team'], "The name you typed does not match this team's name."],
        'different case' => [['name' => 'acme ops'], "The name you typed does not match this team's name."],
    ]);
});

describe('settings page', function () {
    it('tells an admin which roles they may grant and whom they may change', function () {
        $admin = teamActor($this->team, 'admin');
        $owner = teamActor($this->team, 'owner');
        $developer = teamActor($this->team, 'developer');
        $this->team->invitations()->create([
            'email' => 'boss@example.com',
            'team_role_id' => TeamRole::builtIn(TeamRoleKey::Owner)->id,
            'token' => 'owner-invite',
        ]);

        $this->actingAs($admin)
            ->get(route('teams.show', $this->team))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assignableRoles', [
                    ['key' => 'admin', 'label' => 'Admin'],
                    ['key' => 'developer', 'label' => 'Developer'],
                    ['key' => 'viewer', 'label' => 'Viewer'],
                ])
                ->where('can', ['updateTeam' => true, 'deleteTeam' => false, 'leaveTeam' => true, 'inviteMembers' => true])
                ->where('members', fn ($members) => collect($members)->mapWithKeys(fn ($member) => [
                    $member['id'] => [$member['can_change_role'], $member['can_remove']],
                ])->all() === [
                    $this->team->user_id => [false, false],
                    $admin->id => [false, false],
                    $owner->id => [false, false],
                    $developer->id => [true, true],
                ])
                ->where('invitations.0.can_cancel', false));
    });

    it('gives a viewer no management abilities', function () {
        $this->actingAs(teamActor($this->team, 'viewer'))
            ->get(route('teams.show', $this->team))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assignableRoles', [])
                ->where('can', ['updateTeam' => false, 'deleteTeam' => false, 'leaveTeam' => true, 'inviteMembers' => false]));
    });
});
