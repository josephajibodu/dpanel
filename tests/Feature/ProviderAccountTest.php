<?php

use App\Jobs\ValidateProviderJob;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\User;
use App\Services\Providers\DigitalOceanProvider;
use App\Services\Providers\ProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('index', function () {
    it('requires authentication', function () {
        $response = $this->get('/provider-accounts');

        $response->assertRedirect('/login');
    });

    it('shows the provider accounts page', function () {
        $response = $this->actingAs($this->user)
            ->get('/provider-accounts');

        $response->assertOk();
    });
});

describe('create', function () {
    it('shows the create provider account page', function () {
        $response = $this->actingAs($this->user)
            ->get('/provider-accounts/create');

        $response->assertOk();
    });
});

describe('store', function () {
    it('creates a provider account with valid credentials', function () {
        $mock = Mockery::mock(DigitalOceanProvider::class);
        $mock->shouldReceive('setCredentials')->once();
        $mock->shouldReceive('validateCredentials')->once()->andReturn(true);

        $managerMock = Mockery::mock(ProviderManager::class);
        $managerMock->shouldReceive('driver')->with('digitalocean')->andReturn($mock);

        $this->app->instance(ProviderManager::class, $managerMock);

        $response = $this->actingAs($this->user)
            ->post('/provider-accounts', [
                'provider' => 'digitalocean',
                'name' => 'My DO Account',
                'api_token' => 'valid-token-12345',
            ]);

        $response->assertRedirect('/provider-accounts');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('provider_accounts', [
            'user_id' => $this->user->id,
            'provider' => 'digitalocean',
            'name' => 'My DO Account',
            'is_valid' => true,
        ]);
    });

    it('creates account with invalid credentials', function () {
        $mock = Mockery::mock(DigitalOceanProvider::class);
        $mock->shouldReceive('setCredentials')->once();
        $mock->shouldReceive('validateCredentials')->once()->andReturn(false);

        $managerMock = Mockery::mock(ProviderManager::class);
        $managerMock->shouldReceive('driver')->with('digitalocean')->andReturn($mock);

        $this->app->instance(ProviderManager::class, $managerMock);

        $response = $this->actingAs($this->user)
            ->post('/provider-accounts', [
                'provider' => 'digitalocean',
                'name' => 'My DO Account',
                'api_token' => 'invalid-token-12345',
            ]);

        $response->assertRedirect('/provider-accounts');
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('provider_accounts', [
            'user_id' => $this->user->id,
            'is_valid' => false,
        ]);
    });

    it('requires all fields', function () {
        $response = $this->actingAs($this->user)
            ->post('/provider-accounts', []);

        $response->assertSessionHasErrors(['provider', 'name', 'api_token']);
    });

    it('validates provider is a valid enum', function () {
        $response = $this->actingAs($this->user)
            ->post('/provider-accounts', [
                'provider' => 'invalid-provider',
                'name' => 'Test',
                'api_token' => 'token12345',
            ]);

        $response->assertSessionHasErrors(['provider']);
    });
});

describe('show', function () {
    it('displays own provider account', function () {
        $account = ProviderAccount::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/provider-accounts/{$account->id}");

        $response->assertOk();
    });

    it('does not allow viewing other users accounts', function () {
        $otherUser = User::factory()->create();
        $account = ProviderAccount::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/provider-accounts/{$account->id}");

        $response->assertForbidden();
    });
});

describe('destroy', function () {
    it('deletes own provider account', function () {
        $account = ProviderAccount::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/provider-accounts/{$account->id}");

        $response->assertRedirect('/provider-accounts');
        $this->assertDatabaseMissing('provider_accounts', ['id' => $account->id]);
    });

    it('cannot delete account with active servers', function () {
        $account = ProviderAccount::factory()->create([
            'user_id' => $this->user->id,
        ]);

        Server::factory()->create([
            'user_id' => $this->user->id,
            'provider_account_id' => $account->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/provider-accounts/{$account->id}");

        $response->assertRedirect('/provider-accounts');
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('provider_accounts', ['id' => $account->id]);
    });

    it('does not allow deleting other users accounts', function () {
        $otherUser = User::factory()->create();
        $account = ProviderAccount::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/provider-accounts/{$account->id}");

        $response->assertForbidden();
    });
});

describe('validate', function () {
    it('dispatches a validation job', function () {
        Queue::fake();

        $account = ProviderAccount::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/provider-accounts/{$account->id}/validate");

        $response->assertRedirect('/provider-accounts');
        $response->assertSessionHas('success');

        Queue::assertPushed(ValidateProviderJob::class, fn ($job) => $job->providerAccount->id === $account->id);
    });

    it('does not allow validating other users accounts', function () {
        $otherUser = User::factory()->create();
        $account = ProviderAccount::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/provider-accounts/{$account->id}/validate");

        $response->assertForbidden();
    });
});
