<?php

use App\Contracts\ProviderContract;
use App\Data\ProviderRegion;
use App\Data\ProviderSize;
use App\Enums\Provider;
use App\Exceptions\ProviderApiException;
use App\Jobs\ValidateProviderJob;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Providers\AwsProvider;
use App\Services\Providers\DigitalOceanProvider;
use App\Services\Providers\ProviderManager;
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
        $response = $this->get("/{$this->team->slug}/provider-accounts");

        $response->assertRedirect('/login');
    });

    it('shows the provider accounts page', function () {
        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/provider-accounts");

        $response->assertOk();
    });
});

describe('create', function () {
    it('shows the create provider account page', function () {
        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/provider-accounts/create");

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
            ->post("/{$this->team->slug}/provider-accounts", [
                'provider' => 'digitalocean',
                'name' => 'My DO Account',
                'api_token' => 'valid-token-12345',
            ]);

        $response->assertRedirect("/{$this->team->slug}/provider-accounts");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('provider_accounts', [
            'team_id' => $this->team->id,
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
            ->post("/{$this->team->slug}/provider-accounts", [
                'provider' => 'digitalocean',
                'name' => 'My DO Account',
                'api_token' => 'invalid-token-12345',
            ]);

        $response->assertRedirect("/{$this->team->slug}/provider-accounts");
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('provider_accounts', [
            'team_id' => $this->team->id,
            'is_valid' => false,
        ]);
    });

    it('requires all fields', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/provider-accounts", []);

        $response->assertSessionHasErrors(['provider', 'name', 'api_token']);
    });

    it('validates provider is a valid enum', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/provider-accounts", [
                'provider' => 'invalid-provider',
                'name' => 'Test',
                'api_token' => 'token12345',
            ]);

        $response->assertSessionHasErrors(['provider']);
    });

    it('stores an AWS access key pair as the credentials', function () {
        $mock = Mockery::mock(AwsProvider::class);
        $mock->shouldReceive('setCredentials')->once()->with([
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);
        $mock->shouldReceive('validateCredentials')->once()->andReturn(true);

        $managerMock = Mockery::mock(ProviderManager::class);
        $managerMock->shouldReceive('driver')->with('aws')->andReturn($mock);

        $this->app->instance(ProviderManager::class, $managerMock);

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/provider-accounts", [
                'provider' => 'aws',
                'name' => 'My AWS Account',
                'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
                'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ]);

        $response->assertRedirect("/{$this->team->slug}/provider-accounts");
        $response->assertSessionHas('success');

        expect($this->team->providerAccounts()->sole())
            ->provider->toBe(Provider::Aws)
            ->credentials->toBe([
                'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
                'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ]);
    });

    it('requires an access key pair instead of an API token for AWS', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/provider-accounts", [
                'provider' => 'aws',
                'name' => 'My AWS Account',
            ]);

        $response->assertSessionHasErrors([
            'access_key_id' => 'Please enter your access key ID.',
            'secret_access_key' => 'Please enter your secret access key.',
        ]);
        $response->assertSessionDoesntHaveErrors('api_token');
    });
});

describe('show', function () {
    it('displays own provider account', function () {
        $account = ProviderAccount::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/provider-accounts/{$account->id}");

        $response->assertOk();
    });

    it('does not allow viewing other users accounts', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $account = ProviderAccount::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->get("/{$otherTeam->slug}/provider-accounts/{$account->id}");

        $response->assertForbidden();
    });
});

describe('destroy', function () {
    it('deletes own provider account', function () {
        $account = ProviderAccount::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->user)
            ->delete("/{$this->team->slug}/provider-accounts/{$account->id}");

        $response->assertRedirect("/{$this->team->slug}/provider-accounts");
        $this->assertDatabaseMissing('provider_accounts', ['id' => $account->id]);
    });

    it('cannot delete account with active servers', function () {
        $account = ProviderAccount::factory()->forTeam($this->team)->create();

        Server::factory()->forTeam($this->team)->create([
            'provider_account_id' => $account->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/{$this->team->slug}/provider-accounts/{$account->id}");

        $response->assertRedirect("/{$this->team->slug}/provider-accounts");
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('provider_accounts', ['id' => $account->id]);
    });

    it('does not allow deleting other users accounts', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $account = ProviderAccount::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->delete("/{$otherTeam->slug}/provider-accounts/{$account->id}");

        $response->assertForbidden();
    });
});

describe('validate', function () {
    it('dispatches a validation job', function () {
        Queue::fake();

        $account = ProviderAccount::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/provider-accounts/{$account->id}/validate");

        $response->assertRedirect("/{$this->team->slug}/provider-accounts");
        $response->assertSessionHas('success');

        Queue::assertPushed(ValidateProviderJob::class, fn ($job) => $job->providerAccount->id === $account->id);
    });

    it('does not allow validating other users accounts', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $account = ProviderAccount::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->post("/{$otherTeam->slug}/provider-accounts/{$account->id}/validate");

        $response->assertForbidden();
    });
});

describe('catalog', function () {
    beforeEach(function () {
        $this->account = ProviderAccount::factory()->forTeam($this->team)->digitalocean()->create();
        $this->provider = Mockery::mock(ProviderContract::class);
        $this->mock(ProviderManager::class)->shouldReceive('forAccount')->andReturn($this->provider);
    });

    it('returns the regions and sizes, cheapest size first, and saves them', function () {
        $this->provider->shouldReceive('getRegions')->once()->andReturn(collect([
            new ProviderRegion(slug: 'nyc1', name: 'New York 1'),
        ]));
        $this->provider->shouldReceive('getSizes')->once()->andReturn(collect([
            new ProviderSize(slug: 's-2vcpu-4gb', vcpus: 2, memory: 4096, disk: 80, priceMonthly: 24.0),
            new ProviderSize(slug: 's-1vcpu-1gb', vcpus: 1, memory: 1024, disk: 25, priceMonthly: 6.0),
        ]));

        $response = $this->actingAs($this->user)
            ->getJson("/{$this->team->slug}/provider-accounts/{$this->account->id}/catalog");

        $response->assertOk()
            ->assertJsonPath('regions', [['slug' => 'nyc1', 'name' => 'New York 1', 'ipv4_price_monthly' => null]])
            ->assertJsonPath('sizes.*.slug', ['s-1vcpu-1gb', 's-2vcpu-4gb']);
        $this->assertDatabaseHas('provider_regions', ['provider' => 'digitalocean', 'code' => 'nyc1']);
        $this->assertDatabaseHas('provider_sizes', ['provider' => 'digitalocean', 'code' => 's-1vcpu-1gb', 'memory' => '1 GB']);
    });

    it('answers repeat requests without asking the provider again', function () {
        $this->provider->shouldReceive('getRegions')->once()->andReturn(collect());
        $this->provider->shouldReceive('getSizes')->once()->andReturn(collect());

        $url = "/{$this->team->slug}/provider-accounts/{$this->account->id}/catalog";
        $this->actingAs($this->user)->getJson($url)->assertOk();
        $this->actingAs($this->user)->getJson($url)->assertOk();
    });

    it('explains a provider failure and tries again on the next request', function () {
        $this->provider->shouldReceive('getRegions')->twice()->andThrow(new RuntimeException('Undefined array key'));

        $url = "/{$this->team->slug}/provider-accounts/{$this->account->id}/catalog";
        $this->actingAs($this->user)->getJson($url)
            ->assertStatus(502)
            ->assertJsonPath('message', "Couldn't load regions and sizes from DigitalOcean. Try again in a moment.");
        $this->actingAs($this->user)->getJson($url)->assertStatus(502);
    });

    it('says when the provider rejects the API key', function () {
        $this->provider->shouldReceive('getRegions')
            ->andThrow(new ProviderApiException('Unauthorized', 'digitalocean', 401));

        $this->actingAs($this->user)
            ->getJson("/{$this->team->slug}/provider-accounts/{$this->account->id}/catalog")
            ->assertStatus(502)
            ->assertJsonPath('message', "DigitalOcean rejected this account's API key. Update the key in Provider accounts, then try again.");
    });

    it('does not expose another team\'s account through this team', function () {
        $otherTeam = Team::factory()->forUser(User::factory()->create())->create();
        $account = ProviderAccount::factory()->forTeam($otherTeam)->create();

        $this->actingAs($this->user)
            ->getJson("/{$this->team->slug}/provider-accounts/{$account->id}/catalog")
            ->assertNotFound();
    });

    it('does not allow loading other teams accounts', function () {
        $otherTeam = Team::factory()->forUser(User::factory()->create())->create();
        $account = ProviderAccount::factory()->forTeam($otherTeam)->create();

        $this->actingAs($this->user)
            ->getJson("/{$otherTeam->slug}/provider-accounts/{$account->id}/catalog")
            ->assertForbidden();
    });
});
