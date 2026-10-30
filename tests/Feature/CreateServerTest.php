<?php

use App\Contracts\ProviderContract;
use App\Data\KeyPair;
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
    $this->mock(KeyGenerator::class)->shouldReceive('generate')->andReturn(new KeyPair(
        publicKey: 'ssh-ed25519 AAAA test',
        privateKey: 'private',
    ));
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
        ->php_version->toBe('8.3')
        ->web_server->toBe('nginx')
        ->database_type->toBe('mysql');
    expect($server->installedServices()->pluck('type')->all())->toEqualCanonicalizing([
        ServiceType::Php, ServiceType::Nginx, ServiceType::Mysql, ServiceType::Redis,
    ]);
});
