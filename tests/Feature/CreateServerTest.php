<?php

use App\Contracts\ProviderContract;
use App\Data\KeyPair;
use App\Enums\Plan;
use App\Enums\Provider;
use App\Enums\ServerType;
use App\Enums\ServiceType;
use App\Jobs\ProvisionServerJob;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Providers\ProviderManager;
use App\Services\Ssh\KeyGenerator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->providerAccount = ProviderAccount::factory()->forTeam($this->team)->create([
        'provider' => Provider::DigitalOcean,
        'is_valid' => true,
    ]);

    $provider = Mockery::mock(ProviderContract::class);
    $provider->shouldReceive('validateCredentials')->andReturn(true);
    $provider->shouldReceive('createSshKey')->andReturn('ssh-key-123');
    $this->mock(ProviderManager::class)->shouldReceive('forAccount')->andReturn($provider);
    $this->provider = $provider;
    $this->mock(KeyGenerator::class)->shouldReceive('generate')->andReturn(new KeyPair(
        publicKey: 'ssh-ed25519 AAAA test',
        privateKey: 'private',
    ));
});

it('shows the create page without waiting on any provider', function () {
    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/create")
        ->assertOk();

    $this->provider->shouldNotHaveReceived('getRegions');
    $this->provider->shouldNotHaveReceived('getSizes');
});

it('creates a provider server of the chosen type with its preset services', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers", [
            'name' => 'cache-1',
            'provider_account_id' => $this->providerAccount->id,
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'type' => 'cache',
        ]);

    $server = Server::where('name', 'cache-1')->firstOrFail();

    $response->assertRedirect(route('servers.show', [$this->team, $server]));
    expect($server)
        ->type->toBe(ServerType::Cache)
        ->php_version->toBeNull()
        ->web_server->toBeNull()
        ->database_type->toBeNull();
    expect($server->installedServices()->pluck('type')->all())->toBe([ServiceType::Redis]);
    $this->provider->shouldHaveReceived('createSshKey')->with(Mockery::any(), 'ssh-ed25519 AAAA test', 'nyc1');
    Queue::assertPushed(ProvisionServerJob::class, fn (ProvisionServerJob $job) => $job->server->is($server));
});

it('creates an app server with every service when no type is given', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers", [
            'name' => 'app-1',
            'provider_account_id' => $this->providerAccount->id,
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
        ])->assertRedirect();

    $server = Server::where('name', 'app-1')->firstOrFail();

    expect($server)
        ->type->toBe(ServerType::App)
        ->php_version->toBe('8.5')
        ->web_server->toBe('nginx')
        ->database_type->toBe('mysql');
    expect($server->installedServices()->pluck('type')->all())->toEqualCanonicalizing([
        ServiceType::Php, ServiceType::Nginx, ServiceType::Mysql, ServiceType::Redis,
    ]);
});

function createServerIn(Team $team, ProviderAccount $account): TestResponse
{
    return test()->post("/{$team->slug}/servers", [
        'name' => 'web-2',
        'provider_account_id' => $account->id,
        'region' => 'nyc1',
        'size' => 's-1vcpu-1gb',
    ]);
}

it('lets a Hobby owner create their first server', function () {
    Queue::fake();
    $this->user->forceFill(['plan' => Plan::Hobby])->save();

    $this->actingAs($this->user);

    createServerIn($this->team, $this->providerAccount)->assertRedirect();

    expect($this->team->servers()->count())->toBe(1);
});

it('refuses a second server on the Hobby plan', function () {
    $this->user->forceFill(['plan' => Plan::Hobby])->save();
    Server::factory()->forTeam($this->team)->create();

    $this->actingAs($this->user);

    createServerIn($this->team, $this->providerAccount)
        ->assertForbidden()
        ->assertSeeText('The Hobby plan includes 1 server. Upgrade to Pro to add more.');

    expect($this->team->servers()->count())->toBe(1);
    $this->provider->shouldNotHaveReceived('createSshKey');
});

it('refuses the server when another takes the Hobby plan\'s place while it waits on the provider', function () {
    $this->user->forceFill(['plan' => Plan::Hobby])->save();

    $provider = Mockery::mock(ProviderContract::class);
    $provider->shouldReceive('validateCredentials')->andReturn(true);
    $provider->shouldReceive('createSshKey')->andReturnUsing(function () {
        Server::factory()->forTeam($this->team)->create();

        return 'ssh-key-123';
    });
    $provider->shouldReceive('deleteSshKey')->once()->with('ssh-key-123');
    $this->mock(ProviderManager::class)->shouldReceive('forAccount')->andReturn($provider);

    $this->actingAs($this->user);

    createServerIn($this->team, $this->providerAccount)
        ->assertForbidden()
        ->assertSeeText('The Hobby plan includes 1 server. Upgrade to Pro to add more.');

    expect($this->team->servers()->count())->toBe(1);
});

it('counts the servers in every team the owner owns against the Hobby limit', function () {
    $this->user->forceFill(['plan' => Plan::Hobby])->save();
    $otherTeam = Team::factory()->create(['user_id' => $this->user->id]);
    Server::factory()->forTeam($otherTeam)->create();

    $this->actingAs($this->user);

    createServerIn($this->team, $this->providerAccount)->assertForbidden();
});

it('holds a member to the team owner\'s plan rather than their own', function () {
    $this->user->forceFill(['plan' => Plan::Hobby])->save();
    Server::factory()->forTeam($this->team)->create();
    $admin = User::factory()->onPlan(Plan::Team)->adminOf($this->team)->create();

    $this->actingAs($admin);

    createServerIn($this->team, $this->providerAccount)->assertForbidden();
});
