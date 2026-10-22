<?php

use App\Enums\TeamPermission;
use App\Jobs\DeploySiteJob;
use App\Models\Backup;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteCommandRun;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    $this->server = Server::factory()->forTeam($this->team)->active()->create();
    $this->site = Site::factory()->forServer($this->server)->create(['env_content' => "APP_KEY=base64:super-secret\n"]);
    $this->serverPath = "/{$this->team->slug}/servers/{$this->server->id}";
    $this->sitePath = "{$this->serverPath}/sites/{$this->site->id}";

    $this->developer = User::factory()->developerOf($this->team)->create();
    $this->viewer = User::factory()->viewerOf($this->team)->create();
});

describe('developer', function () {
    it('can deploy a site', function () {
        $this->actingAs($this->developer)
            ->post("{$this->sitePath}/deployments")
            ->assertRedirect();

        Queue::assertPushed(DeploySiteJob::class);
    });

    it('cannot restore a database backup', function () {
        $serverDatabase = ServerDatabase::factory()->for($this->server)->create();
        $backup = Backup::factory()->completed()->create([
            'server_database_id' => $serverDatabase->id,
            'storage_provider_id' => StorageProvider::factory()->create(['team_id' => $this->team->id])->id,
        ]);

        $this->actingAs($this->developer)
            ->post("{$this->serverPath}/databases/{$serverDatabase->id}/backups/{$backup->id}/restore")
            ->assertForbidden();

        expect($backup->fresh()->restore_status)->toBeNull();
    });

    it('cannot delete a server', function () {
        $this->actingAs($this->developer)
            ->delete($this->serverPath)
            ->assertForbidden();

        expect($this->server->fresh())->not->toBeNull();
    });

    it('cannot add a provider account', function () {
        $this->actingAs($this->developer)
            ->get("/{$this->team->slug}/provider-accounts/create")
            ->assertForbidden();

        $this->actingAs($this->developer)
            ->post("/{$this->team->slug}/provider-accounts", [
                'provider' => 'digitalocean',
                'name' => 'Main',
                'api_token' => 'token',
            ])
            ->assertForbidden();

        expect($this->team->providerAccounts()->count())->toBe(0);
    });

    it('can see the environment file', function () {
        $this->actingAs($this->developer)
            ->get("{$this->sitePath}/environment")
            ->assertOk();
    });
});

describe('viewer', function () {
    it('can view the server and site pages', function () {
        $this->actingAs($this->viewer)->get($this->serverPath)->assertOk();
        $this->actingAs($this->viewer)->get($this->sitePath)->assertOk();
    });

    it('cannot reveal a database user password', function () {
        $databaseUser = DatabaseUser::factory()->for($this->server)->create(['password' => 'super-secret-password']);

        $this->actingAs($this->viewer)
            ->postJson("{$this->serverPath}/database-users/{$databaseUser->id}/reveal-password")
            ->assertForbidden()
            ->assertDontSee('super-secret-password');
    });

    it('cannot open the environment page or load its contents', function () {
        $this->actingAs($this->viewer)
            ->get("{$this->sitePath}/environment")
            ->assertForbidden()
            ->assertDontSee('super-secret');

        $this->actingAs($this->viewer)
            ->get("{$this->sitePath}/environment", [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'sites/environment/show',
                'X-Inertia-Partial-Data' => 'env_content',
            ])
            ->assertForbidden()
            ->assertDontSee('super-secret');
    });

    it('does not get command output, which can print secrets', function () {
        $commandRun = SiteCommandRun::factory()->for($this->site)->create(['output' => 'DB_PASSWORD=hunter2']);

        $this->actingAs($this->viewer)
            ->getJson("{$this->sitePath}/command-runs/{$commandRun->id}")
            ->assertOk()
            ->assertJsonMissingPath('output')
            ->assertDontSee('hunter2');

        $this->actingAs($this->developer)
            ->getJson("{$this->sitePath}/command-runs/{$commandRun->id}")
            ->assertOk()
            ->assertJsonPath('output', 'DB_PASSWORD=hunter2');
    });

    it('cannot download a backup', function () {
        $serverDatabase = ServerDatabase::factory()->for($this->server)->create();
        $backup = Backup::factory()->completed()->create(['server_database_id' => $serverDatabase->id]);

        $this->actingAs($this->viewer)
            ->get("{$this->serverPath}/databases/{$serverDatabase->id}/backups/{$backup->id}/download")
            ->assertForbidden();
    });

    it('sees the normal error page when an Inertia request is refused', function () {
        $this->actingAs($this->viewer)
            ->post("{$this->sitePath}/deployments", [], ['X-Inertia' => 'true'])
            ->assertForbidden()
            ->assertSee('This action is unauthorized.');

        Queue::assertNotPushed(DeploySiteJob::class);
    });
});

describe('invitations', function () {
    it('invites a member as a Developer who keeps that role after accepting', function () {
        Notification::fake();

        $this->actingAs($this->owner)
            ->post("/teams/{$this->team->slug}/invitations", ['email' => 'new@example.com', 'role' => 'member'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $invitation = TeamInvitation::query()->where('email', 'new@example.com')->sole();
        expect($invitation->role)->toBe('developer');
        Notification::assertSentOnDemand(TeamInvitationNotification::class);

        $invitee = User::factory()->create(['email' => 'new@example.com']);

        $this->actingAs($invitee)
            ->post("/invitations/{$invitation->token}/confirm")
            ->assertRedirect(route('servers.index', $this->team));

        expect($invitee->teamRole($this->team)->key)->toBe('developer')
            ->and($invitee->hasTeamPermission($this->team, TeamPermission::Deploy))->toBeTrue()
            ->and($invitee->hasTeamPermission($this->team, TeamPermission::RestoreBackups))->toBeFalse()
            ->and(TeamInvitation::query()->count())->toBe(0);
    });

    it('invites an owner as an Owner', function () {
        Notification::fake();

        $this->actingAs($this->owner)
            ->post("/teams/{$this->team->slug}/invitations", ['email' => 'boss@example.com', 'role' => 'owner'])
            ->assertSessionHasNoErrors();

        expect(TeamInvitation::query()->where('email', 'boss@example.com')->sole()->role)->toBe('owner');
    });

    it('shows each member role and invited role on the team settings page', function () {
        Notification::fake();
        $this->actingAs($this->owner)
            ->post("/teams/{$this->team->slug}/invitations", ['email' => 'new@example.com', 'role' => 'member']);

        $this->actingAs($this->owner)
            ->get("/teams/{$this->team->slug}/settings")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('members', fn ($members) => collect($members)->pluck('role', 'id')->all() === [
                    $this->developer->id => 'developer',
                    $this->viewer->id => 'viewer',
                ])
                ->where('invitations.0.role', 'developer'));
    });

    it('still lets only the team creator invite people', function () {
        $admin = User::factory()->adminOf($this->team)->create();

        $this->actingAs($admin)
            ->post("/teams/{$this->team->slug}/invitations", ['email' => 'new@example.com', 'role' => 'member'])
            ->assertForbidden();
    });
});
