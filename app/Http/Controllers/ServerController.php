<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateServerAction;
use App\Actions\Servers\DeleteServerAction;
use App\Data\ServerData;
use App\Enums\ServiceType;
use App\Http\Requests\StoreServerRequest;
use App\Http\Resources\ProviderAccountResource;
use App\Http\Resources\ServerResource;
use App\Jobs\RestartServiceJob;
use App\Models\ProviderRegion;
use App\Models\ProviderSize;
use App\Models\Server;
use App\Services\Providers\ProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(): Response
    {
        $servers = auth()->user()
            ->servers()
            ->with('providerAccount')
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

        // Fetch regions and sizes for each provider account and sync to database
        $regions = [];
        $sizes = [];

        foreach ($providerAccounts as $account) {
            try {
                $provider = $providerManager->forAccount($account);

                // Fetch and sync regions
                $providerRegions = $provider->getRegions();
                foreach ($providerRegions as $regionDto) {
                    ProviderRegion::updateOrCreate(
                        [
                            'provider' => $account->provider,
                            'code' => $regionDto->slug,
                        ],
                        [
                            'name' => $regionDto->name,
                        ]
                    );
                }

                // Fetch and sync sizes
                $providerSizes = $provider->getSizes();
                foreach ($providerSizes as $sizeDto) {
                    ProviderSize::updateOrCreate(
                        [
                            'provider' => $account->provider,
                            'code' => $sizeDto->slug,
                        ],
                        [
                            'name' => $sizeDto->description(),
                            'memory' => $this->formatMemory($sizeDto->memory),
                            'disk' => $sizeDto->disk.' GB',
                            'cpus' => $sizeDto->vcpus,
                            'price_monthly' => $sizeDto->priceMonthly,
                        ]
                    );
                }

                $regions[$account->id] = $providerRegions->map->toArray()->all();
                $sizes[$account->id] = $providerSizes->map->toArray()->all();
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

    private function formatMemory(int $mb): string
    {
        return $mb >= 1024 ? ($mb / 1024).' GB' : $mb.' MB';
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
            'providerAccount:id,ulid,name,provider,is_valid,validated_at,created_at,updated_at',
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

    public function restart(Server $server, Request $request): RedirectResponse
    {
        $this->authorize('update', $server);

        $validated = $request->validate([
            'service' => ['required', 'string', Rule::enum(ServiceType::class)],
        ]);

        $service = ServiceType::from($validated['service']);

        // Create a server action record
        $action = $server->actions()->create([
            'user_id' => auth()->id(),
            'action' => "restart_{$service->value}",
            'status' => 'pending',
        ]);

        // Dispatch the job
        RestartServiceJob::dispatch($server, $service, $action);

        return redirect()
            ->back()
            ->with('success', "{$service->label()} restart initiated.");
    }
}
