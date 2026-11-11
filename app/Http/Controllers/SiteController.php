<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ChangeSiteRepositoryAction;
use App\Actions\Sites\CreateSiteAction;
use App\Actions\Sites\DeleteSiteAction;
use App\Actions\Sites\SetDeployOnPushAction;
use App\Data\SiteData;
use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Enums\SiteStatus;
use App\Events\DeploymentStatusChanged;
use App\Events\ServerDatabasesUpdated;
use App\Events\ServerProcessesUpdated;
use App\Events\ServerSitesUpdated;
use App\Http\Requests\CheckSiteNameRequest;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\CronJobResource;
use App\Http\Resources\DatabaseConnectionResource;
use App\Http\Resources\ServerDatabaseResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\SourceControlAccountResource;
use App\Http\Resources\WorkerResource;
use App\Jobs\MoveSitePhpPoolJob;
use App\Jobs\RetryCreateSiteJob;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SourceControlAccount;
use App\Models\Team;
use App\Services\SourceControlService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
            'sites' => Inertia::live(SiteResource::collection($sites), on: new ServerSitesUpdated($server)),
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
                'description' => $type->description(),
                'defaultDirectory' => $type->defaultDirectory(),
            ]),
            'repositoryProviders' => collect(RepositoryProvider::cases())->map(fn ($provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
            ]),
            'phpVersions' => $phpVersions,
            'databases' => Inertia::live(ServerDatabaseResource::collection(
                ServerDatabase::query()
                    ->with('server:id,name')
                    ->whereIn('server_id', $server->reachableDatabaseServerIds())
                    ->orderBy('name')
                    ->get()
            ), on: new ServerDatabasesUpdated($server)),
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

        $processesUpdated = new ServerProcessesUpdated($server->id);

        return Inertia::render('sites/show', [
            'server' => new ServerResource($server),
            'site' => Inertia::live(
                new SiteResource($site),
                on: [DeploymentStatusChanged::class, ServerSitesUpdated::class],
                channel: new PrivateChannel("site.{$site->id}"),
            ),
            'workers' => Inertia::live(WorkerResource::collection($server->workers()->where('site_id', $site->id)->get()), on: $processesUpdated),
            'cronJobs' => Inertia::live(CronJobResource::collection($server->cronJobs()->where('site_id', $site->id)->get()), on: $processesUpdated),
            'databaseConnection' => $site->serverDatabase
                ? DatabaseConnectionResource::make($site->serverDatabase)->resolve()
                : null,
        ]);
    }

    public function edit(Team $team, Server $server, Site $site, SourceControlService $sourceControl): Response
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
            'phpVersions' => $phpVersions,
            'supportsDeployOnPush' => $sourceControl->supportsDeployWebhooks($site),
            'sourceControlAccounts' => SourceControlAccountResource::collection(
                auth()->user()->sourceControlAccounts()->get(),
            ),
        ]);
    }

    public function update(
        UpdateSiteRequest $request,
        Team $team,
        Server $server,
        Site $site,
        SetDeployOnPushAction $setDeployOnPush,
        ChangeSiteRepositoryAction $changeRepository,
    ): RedirectResponse {
        $validated = $request->validated();

        if (array_key_exists('repository', $validated) || array_key_exists('source_control_account_id', $validated)) {
            try {
                $changeRepository->execute(
                    $site,
                    SourceControlAccount::find(array_key_exists('source_control_account_id', $validated)
                        ? $validated['source_control_account_id']
                        : $site->source_control_account_id),
                    array_key_exists('repository', $validated) ? $validated['repository'] : $site->repository,
                );
            } catch (RuntimeException $exception) {
                return back()->withErrors(['repository' => $exception->getMessage()]);
            }
        }

        if (array_key_exists('auto_deploy', $validated)) {
            try {
                $setDeployOnPush->execute($site, (bool) $validated['auto_deploy']);
            } catch (RuntimeException $exception) {
                return back()->withErrors(['auto_deploy' => $exception->getMessage()]);
            }
        }

        $previousPhpVersion = $site->php_version;
        $site->update(Arr::except($validated, ['auto_deploy', 'repository', 'source_control_account_id']));

        $phpVersionChanged = $site->wasChanged('php_version');

        if (($phpVersionChanged || $site->wasChanged(['directory', 'single_page_app'])) && $server->isReady()) {
            if ($phpVersionChanged && $site->isIsolated()) {
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
}
