<?php

namespace App\Http\Controllers;

use App\Actions\Sites\CreateSiteAction;
use App\Actions\Sites\DeleteSiteAction;
use App\Data\SiteData;
use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function create(Server $server): Response
    {
        $this->authorize('create', [Site::class, $server]);

        $server->load('providerAccount');

        return Inertia::render('sites/create', [
            'server' => new ServerResource($server),
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

    public function store(
        StoreSiteRequest $request,
        Server $server,
        CreateSiteAction $action,
    ): RedirectResponse {
        $this->authorize('create', [Site::class, $server]);

        $site = $action->execute(
            server: $server,
            data: SiteData::from($request->validated()),
        );

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site is being created...');
    }

    public function show(Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load([
            'server.providerAccount',
            'latestDeployment',
            'deployScript',
            'deployments' => fn ($q) => $q->latest()->limit(10),
        ]);

        return Inertia::render('sites/show', [
            'site' => new SiteResource($site),
        ]);
    }

    public function edit(Site $site): Response
    {
        $this->authorize('update', $site);

        $site->load('server.providerAccount');

        return Inertia::render('sites/edit', [
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

    public function update(UpdateSiteRequest $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site updated successfully.');
    }

    public function destroy(Site $site, DeleteSiteAction $action): RedirectResponse
    {
        $this->authorize('delete', $site);

        $serverId = $site->server_id;

        $action->execute($site);

        return redirect()
            ->route('servers.show', $serverId)
            ->with('success', 'Site deletion initiated.');
    }
}
