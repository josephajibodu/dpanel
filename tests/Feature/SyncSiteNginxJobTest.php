<?php

use App\Enums\NginxFileSection;
use App\Events\SiteNginxUpdated;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SiteWebServerSyncFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Event::fake([SiteNginxUpdated::class]);
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->server = Server::factory()->forTeam(Team::factory()->forUser($this->owner)->create())->create(['web_server' => 'nginx']);
    $this->site = Site::factory()->forServer($this->server)->create(['domain' => 'example.com']);
    $this->mainConfig = SiteNginxFile::factory()
        ->forDomain($this->site->domains()->firstOrFail())
        ->inSection(NginxFileSection::Main, 'example.com.conf')
        ->create(['sync_status' => 'synced', 'sync_error' => null]);
});

it('marks the main config as not applied, with the reason, when a sync fails for good', function () {
    $snippet = SiteNginxFile::factory()
        ->forDomain($this->site->domains()->firstOrFail())
        ->inSection(NginxFileSection::Server, 'headers.conf')
        ->create(['sync_status' => 'synced']);

    (new SyncSiteNginxJob($this->site))->failed(new RuntimeException('Nginx validation failed: unknown directive "bogus"'));

    expect($this->mainConfig->fresh())
        ->sync_status->toBe('failed')
        ->sync_error->toBe('Nginx validation failed: unknown directive "bogus"');
    expect($snippet->fresh()->sync_status)->toBe('synced');
    Event::assertDispatched(SiteNginxUpdated::class, fn (SiteNginxUpdated $event) => $event->site->is($this->site));
});

it('emails the server owner when a sync fails for good', function () {
    (new SyncSiteNginxJob($this->site))->failed(new RuntimeException('Nginx validation failed: unknown directive "bogus"'));

    Notification::assertSentTo($this->owner, SiteWebServerSyncFailed::class, function (SiteWebServerSyncFailed $notification) {
        return $notification->site->is($this->site)
            && $notification->errorMessage === 'Nginx validation failed: unknown directive "bogus"'
            && $notification->toMail($this->owner)->subject === 'Web server config not applied: example.com';
    });
});

it('removes the server passwords from the reason it records and emails', function () {
    $this->server->credentials()->create(['type' => 'sudo_password', 'value' => 'super-secret-sudo']);

    (new SyncSiteNginxJob($this->site))->failed(new RuntimeException('SSH command failed: echo super-secret-sudo | sudo -S nginx -t'));

    expect($this->mainConfig->fresh()->sync_error)->toBe('SSH command failed: echo [redacted] | sudo -S nginx -t');
    Notification::assertSentTo(
        $this->owner,
        SiteWebServerSyncFailed::class,
        fn (SiteWebServerSyncFailed $notification) => ! str_contains($notification->errorMessage, 'super-secret-sudo'),
    );
});

it('clears the mark on the next sync that works', function () {
    $this->mainConfig->update(['sync_status' => 'failed', 'sync_error' => 'Nginx validation failed: unknown directive "bogus"']);
    $commands = [];
    $uploads = [];
    fakeHostSsh($commands, $uploads);

    (new SyncSiteNginxJob($this->site))->handle();

    expect($this->site->nginxFiles()->where('section', NginxFileSection::Main->value)->get())
        ->not->toBeEmpty()
        ->each(fn ($file) => $file->sync_status->toBe('synced')->sync_error->toBeNull());
    Notification::assertNothingSent();
});
