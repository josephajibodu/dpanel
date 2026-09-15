<?php

use App\Jobs\ValidateStorageProviderJob;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

describe('index', function () {
    it('requires authentication', function () {
        $response = $this->get("/{$this->team->slug}/storage-providers");

        $response->assertRedirect('/login');
    });

    it('shows the storage providers page', function () {
        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/storage-providers");

        $response->assertOk();
    });
});

describe('store', function () {
    it('connects a Cloudflare R2 provider with valid credentials', function () {
        $mock = Mockery::mock(CloudflareR2Driver::class);
        $mock->shouldReceive('setCredentials')->once();
        $mock->shouldReceive('validateCredentials')->once()->andReturn(true);

        $managerMock = Mockery::mock(StorageProviderManager::class);
        $managerMock->shouldReceive('driver')->with('cloudflare_r2')->andReturn($mock);

        $this->app->instance(StorageProviderManager::class, $managerMock);

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/storage-providers", [
                'type' => 'cloudflare_r2',
                'name' => 'My R2 Bucket',
                'account_id' => 'abc123',
                'access_key_id' => 'access-key-12345',
                'secret_access_key' => 'secret-key-12345',
                'bucket' => 'flitops-backups',
            ]);

        $response->assertRedirect("/{$this->team->slug}/storage-providers");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('storage_providers', [
            'team_id' => $this->team->id,
            'type' => 'cloudflare_r2',
            'name' => 'My R2 Bucket',
            'is_valid' => true,
        ]);
    });

    it('connects a provider with invalid credentials', function () {
        $mock = Mockery::mock(CloudflareR2Driver::class);
        $mock->shouldReceive('setCredentials')->once();
        $mock->shouldReceive('validateCredentials')->once()->andReturn(false);

        $managerMock = Mockery::mock(StorageProviderManager::class);
        $managerMock->shouldReceive('driver')->with('cloudflare_r2')->andReturn($mock);

        $this->app->instance(StorageProviderManager::class, $managerMock);

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/storage-providers", [
                'type' => 'cloudflare_r2',
                'name' => 'My R2 Bucket',
                'account_id' => 'abc123',
                'access_key_id' => 'bad-key',
                'secret_access_key' => 'bad-secret',
                'bucket' => 'flitops-backups',
            ]);

        $response->assertRedirect("/{$this->team->slug}/storage-providers");
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('storage_providers', [
            'team_id' => $this->team->id,
            'is_valid' => false,
        ]);
    });

    it('requires the fields the selected type needs', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/storage-providers", [
                'type' => 'cloudflare_r2',
            ]);

        $response->assertSessionHasErrors(['name', 'access_key_id', 'secret_access_key', 'bucket', 'account_id']);
    });

    it('requires region instead of account_id for s3', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/storage-providers", [
                'type' => 's3',
                'name' => 'My S3 Bucket',
                'access_key_id' => 'access-key-12345',
                'secret_access_key' => 'secret-key-12345',
                'bucket' => 'flitops-backups',
            ]);

        $response->assertSessionHasErrors(['region']);
    });

    it('validates type is a valid enum', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/storage-providers", [
                'type' => 'invalid-type',
                'name' => 'Test',
            ]);

        $response->assertSessionHasErrors(['type']);
    });
});

describe('destroy', function () {
    it('deletes own storage provider', function () {
        $provider = StorageProvider::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->user)
            ->delete("/{$this->team->slug}/storage-providers/{$provider->id}");

        $response->assertRedirect("/{$this->team->slug}/storage-providers");
        $this->assertDatabaseMissing('storage_providers', ['id' => $provider->id]);
    });

    it('does not allow deleting other teams providers', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $provider = StorageProvider::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->delete("/{$otherTeam->slug}/storage-providers/{$provider->id}");

        $response->assertForbidden();
    });
});

describe('validate', function () {
    it('dispatches a validation job', function () {
        Queue::fake();

        $provider = StorageProvider::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/storage-providers/{$provider->id}/validate");

        $response->assertRedirect("/{$this->team->slug}/storage-providers");
        $response->assertSessionHas('success');

        Queue::assertPushed(ValidateStorageProviderJob::class, fn ($job) => $job->storageProvider->id === $provider->id);
    });

    it('does not allow validating other teams providers', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $provider = StorageProvider::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->post("/{$otherTeam->slug}/storage-providers/{$provider->id}/validate");

        $response->assertForbidden();
    });
});
