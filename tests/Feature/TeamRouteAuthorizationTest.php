<?php

use App\Models\Backup;
use App\Models\CronJob;
use App\Models\DatabaseUser;
use App\Models\Deployment;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Models\SiteNginxHistory;
use App\Models\SourceControlAccount;
use App\Models\SshKey;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Write routes inside a team that a Viewer may still call. Anything not listed
 * here must answer a Viewer with 403, so a new write route that forgets its
 * permission check fails this test.
 */
const VIEWER_ALLOWED_WRITE_ROUTES = [
    // The viewer's own SSH keys and source-control logins belong to their
    // profile, not to the team. Adding or deleting one changes nothing on the
    // team's servers (deleting a key only takes access away).
    'ssh-keys.store',
    'ssh-keys.destroy',
    'source-control.destroy',
    'source-control.repositories.sync',

    // Re-check the SSH connection or re-read metrics. Both only read from the
    // server, so they belong with "view".
    'servers.test-connection',
    'servers.metrics.refresh',
];

/**
 * Every non-GET route whose URL contains a {team}.
 *
 * @return list<Route>
 */
function teamWriteRoutes(): array
{
    return collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('team', $route->parameterNames(), true))
        ->reject(fn (Route $route): bool => in_array('GET', $route->methods(), true))
        ->values()
        ->all();
}

/**
 * Build one of every resource a team route can point at, all owned by the
 * team (or, for personal resources, by the viewer), keyed by route parameter.
 *
 * @return array<string, Model|string|int>
 */
function teamRouteParameters(Team $team, User $viewer): array
{
    $server = Server::factory()->forTeam($team)->active()->create();
    $site = Site::factory()->for($server)->create();
    $serverDatabase = ServerDatabase::factory()->for($server)->create();
    $storageProvider = StorageProvider::factory()->create(['team_id' => $team->id]);
    $sshKey = SshKey::factory()->create(['user_id' => $viewer->id]);
    $sourceControlAccount = SourceControlAccount::factory()->create(['user_id' => $viewer->id]);
    $providerAccount = ProviderAccount::factory()->create(['team_id' => $team->id]);
    $member = User::factory()->developerOf($team)->create();

    return [
        'team' => $team,
        'server' => $server,
        'site' => $site,
        'site_domain' => $site->domains()->first()->ulid,
        'deployment' => Deployment::factory()->for($site)->create(),
        'nginxFile' => SiteNginxFile::factory()->for($site)->create(),
        'historyId' => SiteNginxHistory::factory()->for($site)->create()->id,
        'server_database' => $serverDatabase,
        'backup' => Backup::factory()->create([
            'server_database_id' => $serverDatabase->id,
            'site_id' => $site->id,
            'storage_provider_id' => $storageProvider->id,
        ]),
        'database_user' => DatabaseUser::factory()->for($server)->create(),
        'worker' => Worker::factory()->for($server)->create(),
        'cron_job' => CronJob::factory()->for($server)->create(),
        'providerAccount' => $providerAccount,
        'provider_account' => $providerAccount,
        'storageProvider' => $storageProvider,
        'storage_provider' => $storageProvider,
        'sshKey' => $sshKey,
        'ssh_key' => $sshKey,
        'sourceControlAccount' => $sourceControlAccount,
        'user' => $member,
        'invitation' => $team->invitations()->create([
            'email' => 'invitee@example.com',
            'team_role_id' => $member->teamRole($team)->id,
            'token' => 'sweep-token',
        ]),
    ];
}

/**
 * @param  array<string, Model|string|int>  $parameters
 */
function teamRouteUrl(Route $route, array $parameters): string
{
    $uri = '/'.$route->uri();

    foreach ($route->parameterNames() as $name) {
        expect($parameters)->toHaveKey($name, message: "No test value for route parameter {{$name}} on {$route->getName()}.");

        $value = $parameters[$name];
        $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) ($value instanceof Model ? $value->getRouteKey() : $value), $uri);
    }

    return $uri;
}

/**
 * Call the URL as the viewer inside a rolled-back transaction, so one route
 * deleting or changing a fixture does not affect the next route.
 */
function viewerStatusFor(TestCase $test, string $method, string $url): int
{
    DB::beginTransaction();

    try {
        return $test->actingAs($test->viewer)->call($method, $url)->getStatusCode();
    } finally {
        DB::rollBack();
    }
}

beforeEach(function () {
    Queue::fake();
    Http::fake();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    $this->viewer = User::factory()->viewerOf($this->team)->create();
});

it('finds the team write routes it is meant to sweep', function () {
    expect(count(teamWriteRoutes()))->toBeGreaterThan(60);
});

it('refuses every team write route to a viewer unless it is on the allow-list', function () {
    $parameters = teamRouteParameters($this->team, $this->viewer);
    $unguarded = [];

    foreach (teamWriteRoutes() as $route) {
        if (in_array($route->getName(), VIEWER_ALLOWED_WRITE_ROUTES, true)) {
            continue;
        }

        $method = collect($route->methods())->first();
        $status = viewerStatusFor($this, $method, teamRouteUrl($route, $parameters));

        if ($status !== 403) {
            $unguarded[] = "{$method} {$route->uri()} ({$route->getName()}) answered {$status}";
        }
    }

    expect($unguarded)->toBe([]);
});

it('lets a viewer reach the allow-listed routes', function () {
    $parameters = teamRouteParameters($this->team, $this->viewer);
    $routes = collect(teamWriteRoutes())
        ->filter(fn (Route $route): bool => in_array($route->getName(), VIEWER_ALLOWED_WRITE_ROUTES, true));

    expect($routes->map->getName()->sort()->values()->all())
        ->toBe(collect(VIEWER_ALLOWED_WRITE_ROUTES)->sort()->values()->all());

    foreach ($routes as $route) {
        $status = viewerStatusFor($this, collect($route->methods())->first(), teamRouteUrl($route, $parameters));

        expect($status)->not->toBe(403, "{$route->getName()} should stay open to viewers.");
    }
});
