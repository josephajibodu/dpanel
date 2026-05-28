<?php

namespace App\Http\Controllers;

use App\Actions\Sites\CreateSiteAction;
use App\Actions\Sites\DeleteSiteAction;
use App\Data\SiteData;
use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\ServerDatabaseResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\SourceControlAccountResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Models\Team;
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

        /** @var \Illuminate\Database\Eloquent\Collection<int, SourceControlAccount> $sourceControlAccounts */
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
            'databases' => ServerDatabaseResource::collection($server->databases),
            'sourceControl' => [
                'accounts' => SourceControlAccountResource::collection($sourceControlAccounts),
            ],
        ]);
    }

    public function store(
        StoreSiteRequest $request,
        Team $team,
        Server $server,
        CreateSiteAction $action,
    ): RedirectResponse {
        $this->authorize('create', [Site::class, $server]);

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
            'deployScript',
            'deployments' => fn ($q) => $q->with('user')->latest()->limit(10),
        ]);

        return Inertia::render('sites/show', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
        ]);
    }

    public function edit(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('update', $site);

        $site->load('server.providerAccount');

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
            'phpVersions' => [
                ['value' => '8.4', 'label' => 'PHP 8.4'],
                ['value' => '8.3', 'label' => 'PHP 8.3'],
                ['value' => '8.2', 'label' => 'PHP 8.2'],
                ['value' => '8.1', 'label' => 'PHP 8.1'],
            ],
        ]);
    }

    public function update(UpdateSiteRequest $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

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
}
