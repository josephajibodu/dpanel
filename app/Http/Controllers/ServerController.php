<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateServerAction;
use App\Actions\Servers\DeleteServerAction;
use App\Data\ServerData;
use App\Http\Requests\StoreServerRequest;
use App\Http\Resources\ProviderAccountResource;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Services\Providers\ProviderManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(): Response
    {
        $servers = auth()->user()
            ->servers()
            ->with('providerAccount:id,name,provider')
            ->withCount('sites')
            ->latest()
            ->paginate(20);

        return Inertia::render('servers/index', [
            'servers' => ServerResource::collection($servers),
        ]);
    }

    public function create(ProviderManager $providerManager): Response
    {
        $providerAccounts = auth()->user()
            ->providerAccounts()
            ->where('is_valid', true)
            ->get();

        // Fetch regions and sizes for each provider account
        $regions = [];
        $sizes = [];

        foreach ($providerAccounts as $account) {
            try {
                $provider = $providerManager->forAccount($account);
                $regions[$account->id] = $provider->getRegions()->map->toArray()->all();
                $sizes[$account->id] = $provider->getSizes()->map->toArray()->all();
            } catch (\Exception $e) {
                // If we can't fetch, provide empty arrays
                $regions[$account->id] = [];
                $sizes[$account->id] = [];
            }
        }

        return Inertia::render('servers/create', [
            'providerAccounts' => ProviderAccountResource::collection($providerAccounts),
            'regions' => $regions,
            'sizes' => $sizes,
        ]);
    }

    public function store(
        StoreServerRequest $request,
        CreateServerAction $action,
    ): RedirectResponse {
        $server = $action->execute(
            user: auth()->user(),
            data: ServerData::from($request->validated()),
        );

        return redirect()
            ->route('servers.show', $server)
            ->with('success', 'Server is being provisioned...');
    }

    public function show(Server $server): Response
    {
        $this->authorize('view', $server);

        $server->load([
            'providerAccount:id,name,provider',
            'sites' => fn ($q) => $q->with('latestDeployment')->latest(),
            'actions' => fn ($q) => $q->latest()->limit(10),
        ]);

        return Inertia::render('servers/show', [
            'server' => new ServerResource($server),
        ]);
    }

    public function destroy(
        Server $server,
        DeleteServerAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $server);

        $action->execute($server);

        return redirect()
            ->route('servers.index')
            ->with('success', 'Server deletion initiated.');
    }
}
