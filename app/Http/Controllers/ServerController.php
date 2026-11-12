<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateCustomServerAction;
use App\Actions\Servers\CreateServerAction;
use App\Actions\Servers\DeleteServerAction;
use App\Data\CustomServerData;
use App\Data\ServerData;
use App\Enums\Provider;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Enums\ServiceType;
use App\Events\DeploymentStatusChanged;
use App\Events\ServerActionsUpdated;
use App\Events\ServerDeleted;
use App\Events\ServerSitesUpdated;
use App\Events\ServerStatusChanged;
use App\Http\Requests\StoreCustomServerRequest;
use App\Http\Requests\StoreServerRequest;
use App\Http\Resources\ProviderAccountResource;
use App\Http\Resources\ServerResource;
use App\Jobs\CollectServerMetricsJob;
use App\Jobs\InstallStackJob;
use App\Jobs\RestartServiceJob;
use App\Jobs\TestServerConnectionJob;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\Team;
use App\Services\ServerNameGenerator;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(Team $team): Response
    {
        $servers = $team->servers()
            ->with('providerAccount', 'installedServices')
            ->withCount('sites')
            ->latest()
            ->paginate(20);

        return Inertia::render('servers/index', [
            'servers' => Inertia::live(
                ServerResource::collection($servers),
                on: [ServerStatusChanged::class, ServerDeleted::class],
                channel: new PrivateChannel("servers.{$team->id}"),
            ),
        ]);
    }

    public function create(Team $team, ServerNameGenerator $nameGenerator): Response
    {
        $this->authorize('create', [Server::class, $team]);

        $providerAccounts = $team->providerAccounts()
            ->where('is_valid', true)
            ->get();

        return Inertia::render('servers/create', [
            'providerAccounts' => ProviderAccountResource::collection($providerAccounts),
            'generatedName' => $nameGenerator->generate(),
            'serverTypes' => array_map(fn (ServerType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'services' => $type->defaultServices(),
            ], ServerType::selectable()),
        ]);
    }

    /**
     * Return a freshly generated, hostname-safe server name.
     */
    public function generateName(ServerNameGenerator $generator): JsonResponse
    {
        return response()->json(['name' => $generator->generate()]);
    }

    public function store(Team $team, StoreServerRequest $request, CreateServerAction $action): RedirectResponse
    {
        $this->ensureRoomForAnotherServer($team);

        $server = $action->execute(
            user: auth()->user(),
            team: $team,
            data: ServerData::from($request->validated()),
        );

        return redirect()
            ->route('servers.show', [$team, $server])
            ->with('success', 'Server is being provisioned...');
    }

    public function storeCustom(Team $team, StoreCustomServerRequest $request, CreateCustomServerAction $action): RedirectResponse
    {
        $this->ensureRoomForAnotherServer($team);

        $server = $action->execute(
            user: auth()->user(),
            team: $team,
            data: CustomServerData::from($request->validated()),
        );

        return redirect()->route('servers.setup', [$team, $server]);
    }

    public function setup(Team $team, Server $server): Response|RedirectResponse
    {
        $this->authorize('view', $server);

        if ($server->status !== ServerStatus::Pending) {
            return redirect()->route('servers.show', [$team, $server]);
        }

        $publicKey = $server->credential('public_key')?->value;
        $command = "mkdir -p /root/.ssh && touch /root/.ssh/authorized_keys && echo \"{$publicKey}\" >> /root/.ssh/authorized_keys";

        return Inertia::render('servers/setup', [
            'server' => new ServerResource($server),
            'authorized_keys_command' => $command,
        ]);
    }

    public function testConnection(Team $team, Server $server): RedirectResponse
    {
        $this->authorize('refreshStatus', $server);

        TestServerConnectionJob::dispatch($server);

        return back();
    }

    public function refreshMetrics(Team $team, Server $server): RedirectResponse
    {
        $this->authorize('refreshStatus', $server);

        CollectServerMetricsJob::dispatch($server);

        return back();
    }

    public function provision(Team $team, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        if (! in_array($server->status, [ServerStatus::Pending, ServerStatus::Error], true)) {
            return redirect()->route('servers.show', [$team, $server]);
        }

        $isRetry = $server->status === ServerStatus::Error;

        $server->update([
            'status' => ServerStatus::Provisioning,
            'provisioning_step' => ProvisioningStep::WaitingForServer,
            'error_message' => null,
        ]);

        if ($isRetry) {
            $server->provisioningLogs()->create([
                'type' => 'info',
                'message' => 'Retrying provisioning...',
                'created_at' => now(),
            ]);
        }

        InstallStackJob::dispatch($server);

        return redirect()
            ->route('servers.show', [$team, $server])
            ->with('success', $isRetry ? 'Retrying provisioning...' : 'Server provisioning started...');
    }

    public function show(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);

        $server->load([
            'providerAccount:id,ulid,name,provider,is_valid,validated_at,created_at,updated_at',
            'sites' => fn ($q) => $q->with('latestDeployment')->latest(),
            'databases' => fn ($q) => $q->latest()->limit(5),
        ]);

        $provisioningLifecycle = [ServerStatus::Pending, ServerStatus::Creating, ServerStatus::Provisioning, ServerStatus::Error];

        return Inertia::render('servers/show', [
            'server' => Inertia::live(
                new ServerResource($server),
                on: [ServerStatusChanged::class, ServerSitesUpdated::class, DeploymentStatusChanged::class],
                channel: new PrivateChannel("server.{$server->id}"),
            ),
            'services' => Inertia::live(
                fn (): array => $this->servicesWithLastRestart($server),
                on: new ServerActionsUpdated($server),
            ),
            'provisioningLogs' => in_array($server->status, $provisioningLifecycle, true)
                ? $server->provisioningLogs()->orderBy('id')->get()->map(fn ($log) => [
                    'type' => $log->type,
                    'message' => $log->message,
                    'created_at' => $log->created_at->toIso8601String(),
                ])
                : [],
            'latestMetric' => Inertia::defer(function () use ($server) {
                $metric = $server->metrics()->latest()->first();

                return $metric ? [
                    'load' => $metric->load,
                    'memory_total' => $metric->memory_total,
                    'memory_used' => $metric->memory_used,
                    'memory_free' => $metric->memory_free,
                    'disk_total' => $metric->disk_total,
                    'disk_used' => $metric->disk_used,
                    'disk_free' => $metric->disk_free,
                    'collected_at' => $metric->created_at->toIso8601String(),
                ] : null;
            }),
        ]);
    }

    public function settings(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);

        $serverUser = config('server.user');
        $sshCommand = $server->ip_address
            ? ($server->ssh_port === 22
                ? "ssh {$serverUser}@{$server->ip_address}"
                : "ssh {$serverUser}@{$server->ip_address} -p {$server->ssh_port}")
            : null;

        $hasSshKey = $server->sshKeys()
            ->where('user_id', auth()->id())
            ->exists();

        return Inertia::render('servers/settings', [
            'server' => new ServerResource($server),
            'ssh_command' => $sshCommand,
            'has_ssh_key' => $hasSshKey,
            'transfer_teams' => auth()->user()->can('transfer', $server)
                ? $this->teamsServerCanMoveTo($team, $server)
                : null,
            'host_key' => $server->hasPinnedHostKey() ? [
                'type' => $server->host_key_type,
                'fingerprint' => $server->host_key_fingerprint,
            ] : null,
        ]);
    }

    /**
     * The user's other teams they may add servers to, each with the cloud
     * accounts the server could be linked to there (none for a custom server),
     * and the plan limit that stops it taking the server, if any. A team with
     * the same owner always has room, since the owner's count doesn't change.
     *
     * @return list<array{slug: string, name: string, provider_accounts: list<array{ulid: string, name: string}>, server_limit: ?array{message: string, plan: string}}>
     */
    private function teamsServerCanMoveTo(Team $currentTeam, Server $server): array
    {
        $user = auth()->user();

        return Team::query()
            ->whereKeyNot($currentTeam->id)
            ->where(fn ($query) => $query
                ->where('user_id', $user->id)
                ->orWhereHas('users', fn ($members) => $members->whereKey($user->id)))
            ->with(['owner', 'providerAccounts' => fn ($accounts) => $accounts
                ->where('provider', $server->provider)
                ->where('is_valid', true)
                ->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Team $team): bool => $user->can('create', [Server::class, $team]))
            ->map(fn (Team $team): array => [
                'slug' => $team->slug,
                'name' => $team->name,
                'provider_accounts' => $team->providerAccounts
                    ->map(fn (ProviderAccount $account): array => ['ulid' => $account->ulid, 'name' => $account->name])
                    ->all(),
                'server_limit' => $team->user_id === $currentTeam->user_id || $team->hasRoomForAnotherServer()
                    ? null
                    : $team->plan()->serverLimitLock(),
            ])
            ->values()
            ->all();
    }

    public function destroy(Team $team, Server $server, DeleteServerAction $action): RedirectResponse
    {
        $this->authorize('delete', $server);

        $action->execute($server);

        return redirect()
            ->route('servers.index', $team)
            ->with('success', $server->provider === Provider::Custom
                ? 'Disconnecting the server. It keeps running, without Flitops.'
                : 'Server deletion initiated.');
    }

    public function restart(Team $team, Server $server, Request $request): RedirectResponse
    {
        $this->authorize('update', $server);

        $validated = $request->validate([
            'service' => ['required', 'string', Rule::in(array_map(
                fn (ServiceType $service): string => $service->value,
                $server->restartableServices(),
            ))],
        ]);

        $service = ServiceType::from($validated['service']);

        $action = $server->actions()->create([
            'user_id' => auth()->id(),
            'action' => "restart_{$service->value}",
            'status' => 'pending',
        ]);

        RestartServiceJob::dispatch($server, $service, $action);

        return redirect()
            ->back()
            ->with('success', "Restarting {$service->label()}.");
    }

    /**
     * The services the server can restart, each with how its last restart went.
     *
     * @return list<array{value: string, label: string, last_restart: array{status: string, error: string|null, at: string}|null}>
     */
    private function servicesWithLastRestart(Server $server): array
    {
        return array_map(function (ServiceType $service) use ($server): array {
            $action = $server->actions()
                ->where('action', "restart_{$service->value}")
                ->latest('id')
                ->first();

            return [
                'value' => $service->value,
                'label' => $service->label(),
                'last_restart' => $action === null ? null : [
                    'status' => $action->status,
                    'error' => $action->error,
                    'at' => ($action->finished_at ?? $action->created_at)->toIso8601String(),
                ],
            ];
        }, $server->restartableServices());
    }

    /**
     * Refuse a new server when the team owner's plan is already at its
     * server limit.
     */
    private function ensureRoomForAnotherServer(Team $team): void
    {
        abort_unless($team->hasRoomForAnotherServer(), 403, $team->plan()->serverLimitMessage());
    }
}
