<?php

namespace App\Http\Controllers;

use App\Actions\Sites\CreateSiteAction;
use App\Actions\Sites\DeleteSiteAction;
use App\Data\SiteData;
use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Enums\SiteStatus;
use App\Http\Requests\CheckSiteNameRequest;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\CronJobResource;
use App\Http\Resources\DatabaseUserResource;
use App\Http\Resources\ServerDatabaseResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\SourceControlAccountResource;
use App\Http\Resources\WorkerResource;
use App\Jobs\MoveSitePhpPoolJob;
use App\Jobs\RetryCreateSiteJob;
use App\Jobs\SyncSiteNginxJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SourceControlAccount;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);

        $sites = $server->sites()
            ->latest()
            ->paginate(20);

        return Inertia::render('servers/sites/index', [
            'server' => new ServerResource($server),
            'sites' => SiteResource::collection($sites),
        ]);
    }

    public function create(Team $team, Server $server): Response
    {
        $this->authorize('create', [Site::class, $server]);

        $server->load('providerAccount');

        /** @var Collection<int, SourceControlAccount> $sourceControlAccounts */
        $sourceControlAccounts = auth()->user()
            ->sourceControlAccounts()
            ->get();

        $phpVersions = $server->installedServices()
            ->where('type', 'php')
            ->get()
            ->map(fn ($s) => [
                'value' => $s->installed_version ?? $s->version,
                'label' => 'PHP '.($s->installed_version ?? $s->version),
            ])
            ->filter(fn ($v) => ! empty($v['value']))
            ->unique('value')
            ->values()
            ->all();

        if (empty($phpVersions)) {
            $defaultVersion = $server->php_version ?? '8.3';
            $phpVersions = [['value' => $defaultVersion, 'label' => 'PHP '.$defaultVersion]];
        }

        return Inertia::render('sites/create', [
            'server' => new ServerResource($server),
            'freeDomain' => config('server.free_domain'),
            'projectTypes' => collect(ProjectType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'defaultDirectory' => $type->defaultDirectory(),
            ]),
            'repositoryProviders' => collect(RepositoryProvider::cases())->map(fn ($provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
            ]),
            'phpVersions' => $phpVersions,
            'databases' => ServerDatabaseResource::collection(
                ServerDatabase::query()
                    ->with('server:id,name')
                    ->whereIn('server_id', $server->reachableDatabaseServerIds())
                    ->orderBy('name')
                    ->get()
            ),
            'sourceControl' => [
                'accounts' => SourceControlAccountResource::collection($sourceControlAccounts),
            ],
        ]);
    }

    public function checkSiteName(CheckSiteNameRequest $request, Team $team, Server $server): JsonResponse
    {
        $siteName = strtolower($request->validated('site_name'));
        $freeDomain = config('server.free_domain');
        $hostname = "{$siteName}.{$freeDomain}";

        if (SiteDomain::query()->where('hostname', $hostname)->exists()) {
            return response()->json([
                'available' => false,
                'message' => 'That subdomain is already taken.',
            ]);
        }

        return response()->json(['available' => true]);
    }

    public function store(
        StoreSiteRequest $request,
        Team $team,
        Server $server,
        CreateSiteAction $action,
    ): RedirectResponse {
        $site = $action->execute(
            server: $server,
            data: SiteData::from($request->validated()),
        );

        return redirect()
            ->route('servers.sites.show', [$team, $server, $site])
            ->with('success', 'Site is being created...');
    }

    public function show(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load([
            'server.providerAccount',
            'latestDeployment',
            'openIncident',
            'deployScript',
            'deployments' => fn ($q) => $q->with('user')->latest()->limit(10),
            'serverDatabase.server',
        ]);

        return Inertia::render('sites/show', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'workers' => WorkerResource::collection($server->workers()->where('site_id', $site->id)->get()),
            'cronJobs' => CronJobResource::collection($server->cronJobs()->where('site_id', $site->id)->get()),
            'databaseConnection' => $this->databaseConnection($site),
        ]);
    }

    public function edit(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('update', $site);

        $site->load('server.providerAccount');

        $phpVersions = $server->installedServices()
            ->where('type', 'php')
            ->get()
            ->map(fn ($s) => [
                'value' => $s->installed_version ?? $s->version,
                'label' => 'PHP '.($s->installed_version ?? $s->version),
            ])
            ->filter(fn ($v) => ! empty($v['value']))
            ->unique('value')
            ->values()
            ->all();

        if (empty($phpVersions)) {
            $defaultVersion = $server->php_version ?? '8.3';
            $phpVersions = [['value' => $defaultVersion, 'label' => 'PHP '.$defaultVersion]];
        }

        return Inertia::render('sites/edit', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'projectTypes' => collect(ProjectType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'defaultDirectory' => $type->defaultDirectory(),
            ]),
            'repositoryProviders' => collect(RepositoryProvider::cases())->map(fn ($provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
            ]),
            'phpVersions' => $phpVersions,
        ]);
    }

    public function update(UpdateSiteRequest $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $previousPhpVersion = $site->php_version;
        $site->update($request->validated());

        if ($previousPhpVersion !== $site->php_version && $server->isReady()) {
            if ($site->isIsolated()) {
                MoveSitePhpPoolJob::dispatch($site, $previousPhpVersion)->onQueue('provisioning');
            } else {
                SyncSiteNginxJob::dispatch($site)->onQueue('provisioning');
            }
        }

        return redirect()
            ->route('servers.sites.show', [$team, $server, $site])
            ->with('success', 'Site updated successfully.');
    }

    public function destroy(Team $team, Server $server, Site $site, DeleteSiteAction $action): RedirectResponse
    {
        $this->authorize('delete', $site);

        $action->execute($site);

        return redirect()
            ->route('servers.sites.index', [$team, $server])
            ->with('success', 'Site deletion initiated.');
    }

    /**
     * Retry creating a site whose creation failed, keeping its settings.
     */
    public function retry(Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $site->creationFailed()) {
            return redirect()->route('servers.sites.show', [$team, $server, $site]);
        }

        $site->update([
            'status' => SiteStatus::Pending,
            'provisioning_step' => null,
            'error_message' => null,
        ]);

        RetryCreateSiteJob::dispatch($site);

        return redirect()
            ->route('servers.sites.show', [$team, $server, $site])
            ->with('success', 'Retrying site creation...');
    }

    /**
     * What the Connect dialog needs for the server database this site uses.
     * Everything comes from the server the database lives on, which can be a
     * different server from the site's own.
     *
     * @return array{server: array<string, mixed>, database: array<string, mixed>, databaseUsers: array<int, mixed>, sshUser: string, hasSshKey: bool}|null
     */
    private function databaseConnection(Site $site): ?array
    {
        $database = $site->serverDatabase;

        if ($database === null) {
            return null;
        }

        $databaseServer = $database->server;
        $usersWithAccess = $databaseServer->databaseUsers()
            ->get()
            ->filter(fn (DatabaseUser $user): bool => in_array($database->name, $user->databases ?? [], true))
            ->values();

        return [
            'server' => $databaseServer->only(['id', 'name', 'ip_address', 'ssh_port', 'database_type']),
            'database' => (new ServerDatabaseResource($database))->resolve(),
            'databaseUsers' => DatabaseUserResource::collection($usersWithAccess)->resolve(),
            'sshUser' => config('server.user'),
            'hasSshKey' => $databaseServer->sshKeys()->where('user_id', auth()->id())->exists(),
        ];
    }
}
