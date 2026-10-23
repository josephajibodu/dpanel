<?php

use App\Jobs\RevokeSshKeyJob;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    $this->owner->switchTeam($this->team);

    $this->member = User::factory()->developerOf($this->team)->create();
});

it('revokes the removed member ssh keys from every server of the team', function () {
    $serverTheyCreated = Server::factory()->forTeam($this->team)->forUser($this->member)->create();
    $ownerServer = Server::factory()->forTeam($this->team)->create();

    $sshKey = SshKey::factory()->create(['user_id' => $this->member->id]);
    $sshKey->servers()->attach([
        $serverTheyCreated->id => ['status' => 'synced'],
        $ownerServer->id => ['status' => 'synced'],
    ]);

    $this->actingAs($this->owner)
        ->delete(route('team-members.destroy', [$this->team, $this->member]))
        ->assertRedirect();

    expect($this->member->fresh()->belongsToTeam($this->team))->toBeFalse();

    Queue::assertPushed(RevokeSshKeyJob::class, 2);
    Queue::assertPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->server->is($serverTheyCreated));
    Queue::assertPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->server->is($ownerServer));

    expect($sshKey->servers()->pluck('server_ssh_key.status')->unique()->all())->toBe(['revoking']);
});

it('leaves the removed member ssh keys on servers of their other teams', function () {
    $teamServer = Server::factory()->forTeam($this->team)->create();
    $otherTeamServer = Server::factory()->forUser($this->member)->create();

    $sshKey = SshKey::factory()->create(['user_id' => $this->member->id]);
    $sshKey->servers()->attach([
        $teamServer->id => ['status' => 'synced'],
        $otherTeamServer->id => ['status' => 'synced'],
    ]);

    $this->actingAs($this->owner)
        ->delete(route('team-members.destroy', [$this->team, $this->member]))
        ->assertRedirect();

    Queue::assertPushed(RevokeSshKeyJob::class, 1);
    Queue::assertNotPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->server->is($otherTeamServer));

    expect($sshKey->servers()->where('servers.id', $otherTeamServer->id)->first()->pivot->status)->toBe('synced');
});

it('does not revoke other members ssh keys', function () {
    $server = Server::factory()->forTeam($this->team)->create();

    $ownerKey = SshKey::factory()->create(['user_id' => $this->owner->id]);
    $ownerKey->servers()->attach($server->id, ['status' => 'synced']);

    $this->actingAs($this->owner)
        ->delete(route('team-members.destroy', [$this->team, $this->member]))
        ->assertRedirect();

    Queue::assertNotPushed(RevokeSshKeyJob::class);
});

it('does not let a non-owner remove a member or revoke their keys', function () {
    $server = Server::factory()->forTeam($this->team)->create();
    $sshKey = SshKey::factory()->create(['user_id' => $this->member->id]);
    $sshKey->servers()->attach($server->id, ['status' => 'synced']);

    $otherMember = User::factory()->developerOf($this->team)->create();

    $this->actingAs($otherMember)
        ->delete(route('team-members.destroy', [$this->team, $this->member]))
        ->assertForbidden();

    expect($this->member->fresh()->belongsToTeam($this->team))->toBeTrue();
    Queue::assertNotPushed(RevokeSshKeyJob::class);
});

describe('leaving a team', function () {
    it('lets a member leave, revokes their keys and sends them to their personal team', function () {
        $personalTeam = Team::factory()->personal()->forUser($this->member)->create();
        $this->member->switchTeam($this->team);

        $server = Server::factory()->forTeam($this->team)->create();
        $sshKey = SshKey::factory()->create(['user_id' => $this->member->id]);
        $sshKey->servers()->attach($server->id, ['status' => 'synced']);

        $this->actingAs($this->member)
            ->delete(route('teams.leave', $this->team))
            ->assertRedirect(route('dashboard', $personalTeam));

        expect($this->member->fresh())
            ->belongsToTeam($this->team)->toBeFalse()
            ->current_team_id->toBe($personalTeam->id);

        Queue::assertPushed(RevokeSshKeyJob::class, fn (RevokeSshKeyJob $job) => $job->server->is($server));
    });

    it('lets the last owner other than the creator leave', function () {
        $owner = User::factory()->ownerOf($this->team)->create();

        $this->actingAs($owner)
            ->delete(route('teams.leave', $this->team))
            ->assertRedirect();

        expect($owner->fresh()->belongsToTeam($this->team))->toBeFalse();
    });

    it('does not let the team creator leave', function () {
        $this->actingAs($this->owner)
            ->delete(route('teams.leave', $this->team))
            ->assertForbidden();

        expect($this->owner->fresh()->belongsToTeam($this->team))->toBeTrue();
    });

    it('does not let anyone leave a personal team', function () {
        $personalTeam = Team::factory()->personal()->create();
        $this->member->teams()->attach($personalTeam, ['team_role_id' => $this->member->teamRole($this->team)->id]);

        $this->actingAs($this->member)
            ->delete(route('teams.leave', $personalTeam))
            ->assertForbidden();

        expect($this->member->fresh()->belongsToTeam($personalTeam))->toBeTrue();
    });

    it('does not let someone outside the team leave it', function () {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->delete(route('teams.leave', $this->team))
            ->assertForbidden();
    });
});
