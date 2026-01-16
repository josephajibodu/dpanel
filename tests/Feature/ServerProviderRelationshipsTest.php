<?php

use App\Actions\Servers\CreateServerAction;
use App\Data\ServerData;
use App\Enums\Provider;
use App\Models\ProviderAccount;
use App\Models\ProviderRegion;
use App\Models\ProviderSize;
use App\Models\Server;
use App\Models\User;
use App\Services\Providers\ProviderManager;
use App\Services\Ssh\KeyGenerator;
use Illuminate\Support\Facades\Queue;

it('can create a provider region', function () {
    $region = ProviderRegion::factory()->digitalOcean()->create([
        'code' => 'nyc1',
        'name' => 'New York 1',
    ]);

    expect($region)
        ->provider->toBe(Provider::DigitalOcean)
        ->code->toBe('nyc1')
        ->name->toBe('New York 1');
});

it('can create a provider size', function () {
    $size = ProviderSize::factory()->digitalOcean()->create([
        'code' => 's-1vcpu-512mb-10gb',
        'name' => '512 MB RAM · 1 vCPU · 10 GB SSD',
        'memory' => '512 MB',
        'disk' => '10 GB',
        'cpus' => 1,
    ]);

    expect($size)
        ->provider->toBe(Provider::DigitalOcean)
        ->code->toBe('s-1vcpu-512mb-10gb')
        ->memory->toBe('512 MB')
        ->disk->toBe('10 GB')
        ->cpus->toBe(1);
});

it('can associate a server with a provider region', function () {
    $region = ProviderRegion::factory()->create();
    $server = Server::factory()->create([
        'provider_region_id' => $region->id,
    ]);

    expect($server->providerRegion)
        ->toBeInstanceOf(ProviderRegion::class)
        ->id->toBe($region->id);
});

it('can associate a server with a provider size', function () {
    $size = ProviderSize::factory()->create();
    $server = Server::factory()->create([
        'provider_size_id' => $size->id,
    ]);

    expect($server->providerSize)
        ->toBeInstanceOf(ProviderSize::class)
        ->id->toBe($size->id);
});

it('can access servers from a provider region', function () {
    $region = ProviderRegion::factory()->create();
    $servers = Server::factory()->count(3)->create([
        'provider_region_id' => $region->id,
    ]);

    expect($region->servers)
        ->toHaveCount(3)
        ->each->toBeInstanceOf(Server::class);
});

it('can access servers from a provider size', function () {
    $size = ProviderSize::factory()->create();
    $servers = Server::factory()->count(2)->create([
        'provider_size_id' => $size->id,
    ]);

    expect($size->servers)
        ->toHaveCount(2)
        ->each->toBeInstanceOf(Server::class);
});

it('provider size returns a human-readable description', function () {
    $size = ProviderSize::factory()->create([
        'memory' => '512 MB',
        'disk' => '10 GB',
        'cpus' => 1,
    ]);

    expect($size->description())->toBe('512 MB RAM · 1 vCPU · 10 GB SSD');

    $largerSize = ProviderSize::factory()->create([
        'memory' => '4 GB',
        'disk' => '80 GB',
        'cpus' => 2,
    ]);

    expect($largerSize->description())->toBe('4 GB RAM · 2 vCPUs · 80 GB SSD');
});

it('CreateServerAction associates provider region and size when they exist', function () {
    Queue::fake();

    // Create a user with a provider account
    $user = User::factory()->create();
    $providerAccount = ProviderAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => Provider::DigitalOcean,
        'is_valid' => true,
    ]);

    // Create provider region and size records
    $region = ProviderRegion::factory()->create([
        'provider' => Provider::DigitalOcean,
        'code' => 'nyc1',
        'name' => 'New York 1',
    ]);

    $size = ProviderSize::factory()->create([
        'provider' => Provider::DigitalOcean,
        'code' => 's-1vcpu-1gb',
        'name' => '1 GB RAM · 1 vCPU · 25 GB SSD',
        'memory' => '1 GB',
        'disk' => '25 GB',
        'cpus' => 1,
    ]);

    // Mock the provider contract
    $mockProvider = Mockery::mock(\App\Contracts\ProviderContract::class);
    $mockProvider->shouldReceive('validateCredentials')->andReturn(true);
    $mockProvider->shouldReceive('createSshKey')->andReturn('ssh-key-123');

    // Mock the provider manager and bind to container
    $this->mock(ProviderManager::class, function ($mock) use ($mockProvider) {
        $mock->shouldReceive('forAccount')->andReturn($mockProvider);
    });

    // Mock the key generator and bind to container
    $this->mock(KeyGenerator::class, function ($mock) {
        $mock->shouldReceive('generate')->andReturn(new \App\Data\KeyPair(
            publicKey: 'ssh-rsa AAAA...',
            privateKey: '-----BEGIN RSA PRIVATE KEY-----...',
        ));
    });

    // Resolve the action from the container
    $action = app(CreateServerAction::class);

    $serverData = new ServerData(
        name: 'test-server',
        providerAccountId: $providerAccount->id,
        region: 'nyc1',
        size: 's-1vcpu-1gb',
    );

    $server = $action->execute($user, $serverData);

    // Verify relationships are properly associated
    expect($server)
        ->provider_region_id->toBe($region->id)
        ->provider_size_id->toBe($size->id)
        ->providerRegion->toBeInstanceOf(ProviderRegion::class)
        ->providerSize->toBeInstanceOf(ProviderSize::class);

    expect($server->providerRegion)
        ->code->toBe('nyc1')
        ->name->toBe('New York 1');

    expect($server->providerSize)
        ->code->toBe('s-1vcpu-1gb')
        ->cpus->toBe(1);
});

it('CreateServerAction handles missing provider region and size gracefully', function () {
    Queue::fake();

    // Create a user with a provider account
    $user = User::factory()->create();
    $providerAccount = ProviderAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => Provider::DigitalOcean,
        'is_valid' => true,
    ]);

    // Don't create any region/size records - they should be null

    // Mock the provider contract
    $mockProvider = Mockery::mock(\App\Contracts\ProviderContract::class);
    $mockProvider->shouldReceive('validateCredentials')->andReturn(true);
    $mockProvider->shouldReceive('createSshKey')->andReturn('ssh-key-123');

    // Mock the provider manager and bind to container
    $this->mock(ProviderManager::class, function ($mock) use ($mockProvider) {
        $mock->shouldReceive('forAccount')->andReturn($mockProvider);
    });

    // Mock the key generator and bind to container
    $this->mock(KeyGenerator::class, function ($mock) {
        $mock->shouldReceive('generate')->andReturn(new \App\Data\KeyPair(
            publicKey: 'ssh-rsa AAAA...',
            privateKey: '-----BEGIN RSA PRIVATE KEY-----...',
        ));
    });

    // Resolve the action from the container
    $action = app(CreateServerAction::class);

    $serverData = new ServerData(
        name: 'test-server-2',
        providerAccountId: $providerAccount->id,
        region: 'unknown-region',
        size: 'unknown-size',
    );

    $server = $action->execute($user, $serverData);

    // Verify the server is created but without relationships
    expect($server)
        ->provider_region_id->toBeNull()
        ->provider_size_id->toBeNull()
        ->region->toBe('unknown-region')
        ->size->toBe('unknown-size');
});
