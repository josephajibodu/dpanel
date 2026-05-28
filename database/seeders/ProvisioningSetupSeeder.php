<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\Provider;
use App\Enums\RepositoryProvider;
use App\Enums\ServiceStatus;
use App\Models\Deployment;
use App\Models\ProviderAccount;
use App\Models\ProviderRegion;
use App\Models\ProviderSize;
use App\Models\Server;
use App\Models\Service;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic provisioning setup: user, provider accounts, servers (active and pending),
 * sites, optional source control, deployments, and server services.
 * Use this to develop and fix the UI with representative data.
 */
class ProvisioningSetupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $this->seedTeam($user);

        $accounts = $this->seedProviderAccounts($user);
        $regions = $this->seedProviderRegionsAndSizes();
        $servers = $this->seedServers($user, $accounts, $regions);
        $sourceControl = $this->seedSourceControl($user);
        $sites = $this->seedSites($servers, $sourceControl);
        $this->seedDeployments($sites, $user);
        $this->seedServerServices($servers);
    }

    private function seedTeam(User $user): void
    {
        // Find an existing personal team for this user, or create one.
        $team = Team::where('user_id', $user->id)->where('personal_team', true)->first();

        if (! $team) {
            $team = app(CreateTeam::class)->execute($user, "Test User's Team", personalTeam: true, slug: 'test-user');
        } elseif (! $team->hasUser($user)) {
            $team->users()->attach($user, ['role' => 'owner']);
        }

        // Ensure current_team_id is set so all team-scoped queries work.
        if ($user->current_team_id !== $team->id) {
            $user->switchTeam($team);
        }
    }

    /**
     * @return array<string, ProviderAccount>
     */
    private function seedProviderAccounts(User $user): array
    {
        $byProvider = [];

        foreach ([Provider::Hetzner, Provider::DigitalOcean] as $provider) {
            $byProvider[$provider->value] = ProviderAccount::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => $provider,
                ],
                [
                    'name' => $provider->label().' Main',
                    'credentials' => ['api_token' => 'fake-token-for-seeding'],
                    'is_valid' => true,
                    'validated_at' => now(),
                ]
            );
        }

        return $byProvider;
    }

    /**
     * @return array{regions: array<string, ProviderRegion>, sizes: array<string, ProviderSize>}
     */
    private function seedProviderRegionsAndSizes(): array
    {
        $regions = [];
        $sizes = [];

        $regionCodes = [
            Provider::Hetzner->value => 'fsn1',
            Provider::DigitalOcean->value => 'nyc1',
            Provider::Vultr->value => 'ewr',
        ];

        $sizeCodes = [
            Provider::Hetzner->value => 'cx21',
            Provider::DigitalOcean->value => 's-1vcpu-1gb',
            Provider::Vultr->value => 'vc2-1c-1gb',
        ];

        foreach (Provider::cases() as $provider) {
            $regions[$provider->value] = ProviderRegion::firstOrCreate(
                ['provider' => $provider, 'code' => $regionCodes[$provider->value] ?? $provider->value.'-1'],
                ['name' => $provider->label().' Region']
            );

            $sizes[$provider->value] = ProviderSize::firstOrCreate(
                [
                    'provider' => $provider,
                    'code' => $sizeCodes[$provider->value] ?? 'small',
                ],
                [
                    'name' => 'Small',
                    'label' => '1 vCPU, 2 GB',
                    'memory' => '2 GB',
                    'disk' => '20 GB',
                    'cpus' => 1,
                    'price_monthly' => 4.99,
                ]
            );
        }

        return ['regions' => $regions, 'sizes' => $sizes];
    }

    /**
     * @param  array<string, ProviderAccount>  $accounts
     * @param  array{regions: array<string, ProviderRegion>, sizes: array<string, ProviderSize>}  $regions
     * @return array<int, Server>
     */
    private function seedServers(User $user, array $accounts, array $regions): array
    {
        $servers = [];
        $team = $user->currentTeam;

        $hetzner = $accounts[Provider::Hetzner->value] ?? null;
        $digitalOcean = $accounts[Provider::DigitalOcean->value] ?? null;

        if ($hetzner) {
            $servers[] = $team->servers()->where('name', 'iha-backend-server')->first()
                ?? Server::factory()
                    ->forUser($user)
                    ->forProviderAccount($hetzner)
                    ->app()
                    ->active()
                    ->create([
                        'name' => 'iha-backend-server',
                        'ip_address' => '45.79.137.246',
                        'region' => 'fsn1',
                        'size' => 'cx21',
                    ]);
        }

        if ($digitalOcean) {
            $servers[] = $team->servers()->where('name', 'mirthful-morning')->first()
                ?? Server::factory()
                    ->forUser($user)
                    ->forProviderAccount($digitalOcean)
                    ->app()
                    ->active()
                    ->create([
                        'name' => 'mirthful-morning',
                        'ip_address' => '88.198.184.29',
                        'region' => 'nyc1',
                        'size' => 's-1vcpu-1gb',
                    ]);
        }

        $servers[] = $team->servers()->where('name', 'pending-server')->first()
            ?? Server::factory()
                ->forUser($user)
                ->forProviderAccount($hetzner ?? $digitalOcean)
                ->app()
                ->active()
                ->create([
                    'name' => 'pending-server',
                    'ip_address' => '65.21.100.42',
                ]);

        return $servers;
    }

    private function seedSourceControl(User $user): ?SourceControlAccount
    {
        return SourceControlAccount::firstOrCreate(
            [
                'user_id' => $user->id,
                'provider' => RepositoryProvider::Github,
            ],
            [
                'provider_user_id' => (string) fake()->randomNumber(6),
                'provider_username' => 'demo-user',
                'name' => 'Demo User',
                'email' => $user->email,
                'avatar_url' => null,
                'token' => encrypt('gho_fake'),
                'connected_at' => now(),
            ]
        );
    }

    /**
     * @param  array<int, Server>  $servers
     * @return array<int, Site>
     */
    private function seedSites(array $servers, ?SourceControlAccount $sourceControl): array
    {
        $sites = [];
        $activeServers = array_filter($servers, fn (Server $s) => $s->status->value === 'active');

        foreach ($activeServers as $server) {
            $count = $server->name === 'iha-backend-server' ? 2 : 1;
            for ($i = 0; $i < $count; $i++) {
                $domain = $i === 0 ? $server->name.'.flitops.test' : 'api.'.$server->name.'.flitops.test';

                $site = $server->sites()->where('domain', $domain)->first()
                    ?? Site::factory()
                        ->forServer($server)
                        ->deployed()
                        ->create([
                            'domain' => $domain,
                            'site_name' => $i === 0 ? $server->name : 'API ('.$server->name.')',
                            'repository' => 'cremir/flitops',
                            'source_control_account_id' => $sourceControl?->id,
                        ]);

                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * @param  array<int, Site>  $sites
     */
    private function seedDeployments(array $sites, User $user): void
    {
        foreach (array_slice($sites, 0, 4) as $site) {
            Deployment::factory()
                ->forSite($site)
                ->forUser($user)
                ->count(fake()->numberBetween(1, 3))
                ->create();
        }
    }

    /**
     * @param  array<int, Server>  $servers
     */
    private function seedServerServices(array $servers): void
    {
        foreach ($servers as $server) {
            if ($server->status->value !== 'active') {
                continue;
            }

            $types = ['php', 'nginx', 'database', 'redis', 'supervisor'];
            foreach ($types as $type) {
                Service::firstOrCreate(
                    [
                        'server_id' => $server->id,
                        'type' => $type,
                    ],
                    [
                        'version' => $type === 'php' ? '8.3' : null,
                        'installed_version' => $type === 'php' ? '8.3' : null,
                        'unit' => match ($type) {
                            'php' => 'php8.3-fpm',
                            'nginx' => 'nginx',
                            'database' => 'mysql',
                            'redis' => 'redis-server',
                            'supervisor' => 'supervisor',
                            default => $type,
                        },
                        'status' => ServiceStatus::Active,
                        'is_default' => true,
                    ]
                );
            }
        }
    }
}
