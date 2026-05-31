<?php

use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

const VALID_SSH_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC6nEXz5T1X6J8sB1oT4yQwertyuiopasdfghjklzxcv test@example.com';

it('stores an ssh key under the team prefix', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'My MacBook',
            'public_key' => VALID_SSH_PUBLIC_KEY,
        ])
        ->assertRedirect(route('ssh-keys.index', $this->team))
        ->assertSessionHas('success', 'SSH key added successfully.');

    $this->assertDatabaseHas('ssh_keys', [
        'user_id' => $this->user->id,
        'name' => 'My MacBook',
    ]);
});

it('rejects an invalid ssh public key', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'Bad Key',
            'public_key' => 'not-a-valid-key',
        ])
        ->assertSessionHasErrors('public_key');

    $this->assertDatabaseCount('ssh_keys', 0);
});

it('rejects duplicate ssh keys', function () {
    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'First Key',
            'public_key' => VALID_SSH_PUBLIC_KEY,
        ])
        ->assertRedirect();

    $this->actingAs($this->user)
        ->post(route('ssh-keys.store', $this->team), [
            'name' => 'Duplicate Key',
            'public_key' => VALID_SSH_PUBLIC_KEY,
        ])
        ->assertSessionHasErrors('public_key');

    $this->assertDatabaseCount('ssh_keys', 1);
});

it('deletes an ssh key under the team prefix', function () {
    $sshKey = SshKey::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->delete(route('ssh-keys.destroy', [$this->team, $sshKey]))
        ->assertRedirect(route('ssh-keys.index', $this->team))
        ->assertSessionHas('success', 'SSH key deleted successfully.');

    $this->assertDatabaseMissing('ssh_keys', ['id' => $sshKey->id]);
});
