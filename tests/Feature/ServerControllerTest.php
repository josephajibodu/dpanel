<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refreshes the servers list when a team server changes status or is deleted', function () {
    $user = User::factory()->create();
    $team = Team::factory()->forUser($user)->create();
    $user->switchTeam($team);

    $response = $this->actingAs($user)->get("/{$team->slug}/servers");

    $response->assertOk();
    expect(liveProps($response))->toBe([
        'servers' => [
            "private:servers.{$team->id} server.status.changed",
            "private:servers.{$team->id} server.deleted",
        ],
    ]);
});
