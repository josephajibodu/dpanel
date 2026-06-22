<?php

use App\Enums\ConnectionStatus;
use App\Enums\NginxFileSection;
use App\Enums\NginxHistoryEvent;
use App\Jobs\ApplySiteNginxFileJob;
use App\Jobs\DeleteSiteNginxFileJob;
use App\Jobs\SyncSiteNginxFilesJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Models\SiteNginxHistory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => \App\Enums\ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
    ]);
    $this->site = Site::factory()->forServer($this->server)->create();
    $this->domain = $this->site->domains()->firstOrFail();
});

it('renders the nginx index page with files and history', function () {
    SiteNginxFile::factory()->forSite($this->site)->inSection(NginxFileSection::Server, 'security.conf')->create();
    SiteNginxHistory::factory()->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/nginx/index')
            ->has('nginxFiles', 1)
            ->has('nginxHistory', 1)
            ->has('sections', 5)
            ->has('domains', 1)
            ->where('sections.0.value', 'main')
            ->where('sections.0.read_only', true)
            ->where('sections.1.read_only', false)
        );
});

it('denies guest access to nginx index', function () {
    $this->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx")
        ->assertRedirect();
});

it('denies access to another users site nginx page', function () {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx")
        ->assertForbidden();
});

it('creates a nginx file and dispatches apply job', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx", [
            'domain_id' => $this->domain->id,
            'section' => 'server',
            'name' => 'security.conf',
            'content' => "add_header X-Frame-Options SAMEORIGIN;\n",
        ]);

    $response->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('site_nginx_files', [
        'site_id' => $this->site->id,
        'site_domain_id' => $this->domain->id,
        'path' => 'server/security.conf',
        'sync_status' => 'pending',
    ]);

    Queue::assertPushed(ApplySiteNginxFileJob::class, fn ($job) => $job->historyEvent === NginxHistoryEvent::Created
    );
});

it('creates a locations file with sub_path', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx", [
            'domain_id' => $this->domain->id,
            'section' => 'locations',
            'name' => 'performance.conf',
            'sub_path' => 'php',
            'content' => "fastcgi_buffers 16 16k;\n",
        ]);

    $this->assertDatabaseHas('site_nginx_files', [
        'site_id' => $this->site->id,
        'site_domain_id' => $this->domain->id,
        'path' => 'locations/php/performance.conf',
    ]);
});

it('rejects a duplicate path on create', function () {
    SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Server, 'security.conf')->create();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx", [
            'domain_id' => $this->domain->id,
            'section' => 'server',
            'name' => 'security.conf',
            'content' => "# duplicate\n",
        ]);

    $response->assertSessionHasErrors('name');
});

it('validates that the filename ends in .conf', function () {
    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx", [
            'domain_id' => $this->domain->id,
            'section' => 'server',
            'name' => 'badname',
            'content' => '# test',
        ]);

    $response->assertSessionHasErrors('name');
});

it('updates a nginx file and dispatches apply job', function () {
    Queue::fake();

    $file = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Server, 'security.conf')->create([
        'content' => "# old\n",
    ]);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/{$file->id}", [
            'content' => "# new content\n",
        ]);

    $response->assertRedirect()->assertSessionHas('success');

    $file->refresh();
    expect($file->content)->toContain('# new content');
    expect($file->sync_status)->toBe('pending');

    Queue::assertPushed(ApplySiteNginxFileJob::class, fn ($job) => $job->historyEvent === NginxHistoryEvent::Updated && $job->oldContent === "# old\n"
    );
});

it('renames a nginx file and dispatches apply job', function () {
    Queue::fake();

    $file = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Server, 'security.conf')->create();

    $response = $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/{$file->id}/rename", [
            'name' => 'headers.conf',
        ]);

    $response->assertRedirect()->assertSessionHas('success');

    $file->refresh();
    expect($file->path)->toBe('server/headers.conf');

    Queue::assertPushed(ApplySiteNginxFileJob::class, fn ($job) => $job->historyEvent === NginxHistoryEvent::Renamed && $job->oldPath === 'server/security.conf'
    );
});

it('deletes a nginx file and dispatches delete job', function () {
    Queue::fake();

    $file = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Server, 'security.conf')->create([
        'content' => "# to be deleted\n",
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/{$file->id}");

    $response->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseMissing('site_nginx_files', ['id' => $file->id]);

    Queue::assertPushed(DeleteSiteNginxFileJob::class, fn ($job) => $job->path === 'server/security.conf' && $job->deletedContent === "# to be deleted\n"
    );
});

it('restores a previous version', function () {
    Queue::fake();

    $file = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Server, 'security.conf')->create([
        'content' => "# current\n",
    ]);

    $history = SiteNginxHistory::factory()->forSite($this->site)->create([
        'site_nginx_file_id' => $file->id,
        'event' => NginxHistoryEvent::Updated,
        'path' => 'server/security.conf',
        'old_content' => "# old\n",
        'new_content' => "# restored content\n",
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx-history/{$history->id}/restore");

    $response->assertRedirect()->assertSessionHas('success');

    $file->refresh();
    expect($file->content)->toBe("# restored content\n");

    Queue::assertPushed(ApplySiteNginxFileJob::class);
});

it('returns history content via json endpoint', function () {
    $history = SiteNginxHistory::factory()->forSite($this->site)->create([
        'old_content' => '# old',
        'new_content' => '# new',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx-history/{$history->id}");

    $response->assertOk()
        ->assertJson(['old_content' => '# old', 'new_content' => '# new']);
});

it('redirects with error when server is not ready on store', function () {
    $this->server->update(['connection_status' => ConnectionStatus::Failed]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx", [
            'domain_id' => $this->domain->id,
            'section' => 'server',
            'name' => 'test.conf',
            'content' => '# test',
        ]);

    $response->assertRedirect()->assertSessionHas('error');
});

it('rejects creating a main-section file', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx", [
            'domain_id' => $this->domain->id,
            'section' => 'main',
            'name' => 'example.conf',
            'content' => '# main',
        ]);

    $response->assertRedirect()->assertSessionHas('error');
    Queue::assertNothingPushed();
});

it('rejects renaming a main-section file', function () {
    Queue::fake();

    $file = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Main, 'site.conf')->create();

    $response = $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/{$file->id}/rename", [
            'name' => 'renamed.conf',
        ]);

    $response->assertRedirect()->assertSessionHas('error');
    Queue::assertNothingPushed();
});

it('rejects deleting a main-section file', function () {
    Queue::fake();

    $file = SiteNginxFile::factory()->forDomain($this->domain)->inSection(NginxFileSection::Main, 'site.conf')->create();

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/{$file->id}");

    $response->assertRedirect()->assertSessionHas('error');
    $this->assertDatabaseHas('site_nginx_files', ['id' => $file->id]);
    Queue::assertNothingPushed();
});

it('dispatches sync job on refresh', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/refresh");

    $response->assertRedirect()->assertSessionHas('success');
    Queue::assertPushed(SyncSiteNginxFilesJob::class);
});

it('blocks refresh when server is not ready', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Failed]);

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/nginx/refresh")
        ->assertRedirect()->assertSessionHas('error');

    Queue::assertNothingPushed();
});
