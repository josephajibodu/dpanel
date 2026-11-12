<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\Plan;
use App\Enums\TeamRoleKey;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One account per plan, for checking the plan limits by eye. Each logs in
 * with the password "password":
 *
 * - hobby@example.com: Hobby, with its one server already used, so Create
 *   server, Invite member, Create team, Backup now and uptime show locked.
 *   It is also an admin of the Team account's client team, which runs on
 *   that team's owner's plan and so shows no limits.
 * - pro@example.com: Pro, with two servers; backups and uptime are open,
 *   members and extra teams are locked.
 * - team@example.com: Team, with two servers and a second "Client Co" team.
 *
 * The servers are fake (documentation IP addresses) and the sites don't
 * resolve. An account that already exists is left as it is, so this is
 * safe to run again.
 */
class PlanAccountsSeeder extends Seeder
{
    /**
     * The last part of the next documentation IP address (203.0.113.0/24)
     * handed to a seeded server.
     */
    private int $nextAddress = 10;

    public function __construct(private CreateTeam $createTeam) {}

    public function run(): void
    {
        $hobby = $this->seedAccount(Plan::Hobby, serverCount: 1);
        $this->seedAccount(Plan::Pro, serverCount: 2);
        $team = $this->seedAccount(Plan::Team, serverCount: 2);

        if ($hobby && $team) {
            $this->seedClientTeam($team, $hobby);
        }
    }

    /**
     * The plan's user and personal team, with a cloud account, a storage
     * provider and servers. The first server gets a site with a database.
     * Returns null when the account already exists.
     */
    private function seedAccount(Plan $plan, int $serverCount): ?User
    {
        $email = "{$plan->value}@example.com";

        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        $user = User::query()->forceCreate([
            'name' => "{$plan->label()} User",
            'email' => $email,
            'password' => 'password',
            'email_verified_at' => now(),
            'plan' => $plan,
        ]);

        $team = $this->createTeam->execute($user, "{$plan->label()} User's Team", personalTeam: true, slug: "{$plan->value}-plan");
        $user->switchTeam($team);

        $this->seedServers($team, $serverCount);

        return $user;
    }

    /**
     * A client team owned by the Team account, with the Hobby user as an
     * admin and one server.
     */
    private function seedClientTeam(User $owner, User $hobby): void
    {
        $client = $this->createTeam->execute($owner, 'Client Co', slug: 'team-plan-client');
        $client->users()->attach($hobby, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Admin)->id]);

        $this->seedServers($client, 1);
    }

    private function seedServers(Team $team, int $count): void
    {
        $account = ProviderAccount::factory()->forTeam($team)->hetzner()->create(['name' => 'Hetzner (seeded)']);
        StorageProvider::factory()->forTeam($team)->create(['name' => 'Backups (seeded)']);

        for ($number = 1; $number <= $count; $number++) {
            $server = Server::factory()
                ->forProviderAccount($account)
                ->app()
                ->active()
                ->create([
                    'name' => "{$team->slug}-web-{$number}",
                    'ip_address' => '203.0.113.'.$this->nextAddress++,
                ]);

            if ($number === 1) {
                $database = ServerDatabase::factory()->create(['server_id' => $server->id, 'name' => 'app']);

                Site::factory()->forServer($server)->deployed()->create([
                    'domain' => "{$team->slug}.example.com",
                    'site_name' => "{$team->slug}.example.com",
                    'server_database_id' => $database->id,
                ]);
            }
        }
    }
}
