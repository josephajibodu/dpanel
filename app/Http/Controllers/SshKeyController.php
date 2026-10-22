<?php

namespace App\Http\Controllers;

use App\Actions\SshKeys\SyncSshKeyAction;
use App\Enums\TeamPermission;
use App\Http\Requests\StoreSshKeyRequest;
use App\Http\Requests\SyncSshKeyRequest;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SshKeyResource;
use App\Jobs\RevokeSshKeyJob;
use App\Models\ServerSshKey;
use App\Models\SshKey;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SshKeyController extends Controller
{
    public function index(Team $team): Response
    {
        $sshKeys = auth()->user()
            ->sshKeys()
            ->with('servers:id,ulid,name')
            ->withCount('servers')
            ->latest()
            ->get();

        $servers = $team->servers()
            ->where('status', 'active')
            ->with(['sites' => fn ($query) => $query->whereNotNull('system_user')->orderBy('domain')])
            ->get();

        $isolatedSites = $servers->flatMap(fn ($server) => $server->sites->map(fn ($site) => [
            'id' => $site->id,
            'domain' => $site->domain,
            'system_user' => $site->system_user,
            'server_id' => $server->id,
            'server_name' => $server->name,
        ]))->values();

        return Inertia::render('ssh-keys/index', [
            'sshKeys' => SshKeyResource::collection($sshKeys),
            'servers' => ServerResource::collection($servers),
            'isolatedSites' => $isolatedSites,
            'canSyncToServerUser' => auth()->user()->hasTeamPermission($team, TeamPermission::AccessServersAsAdmin),
        ]);
    }

    public function store(StoreSshKeyRequest $request, Team $team): RedirectResponse
    {
        auth()->user()->sshKeys()->create([
            'name' => $request->validated('name'),
            'public_key' => trim($request->validated('public_key')),
            'fingerprint' => $request->calculateFingerprint($request->validated('public_key')),
        ]);

        return redirect()
            ->route('ssh-keys.index', $team)
            ->with('success', 'SSH key added successfully.');
    }

    public function destroy(Team $team, SshKey $sshKey): RedirectResponse
    {
        $this->authorize('delete', $sshKey);

        // Revoke from every server user and site user it's on first
        foreach ($sshKey->servers as $server) {
            RevokeSshKeyJob::dispatch($sshKey, $server, $server->pivot->system_user);
        }

        $sshKey->delete();

        return redirect()
            ->route('ssh-keys.index', $team)
            ->with('success', 'SSH key deleted successfully.');
    }

    public function sync(Team $team, SshKey $sshKey, SyncSshKeyRequest $request, SyncSshKeyAction $action): RedirectResponse
    {
        $action->execute($team, $sshKey, $request->validated('server_ids') ?? [], $request->validated('site_ids') ?? []);

        return redirect()
            ->route('ssh-keys.index', $team)
            ->with('success', 'SSH key sync initiated.');
    }

    public function revoke(Team $team, SshKey $sshKey, SyncSshKeyRequest $request): RedirectResponse
    {
        $serverIds = $request->validated('server_ids') ?? [];
        $siteIds = $request->validated('site_ids') ?? [];

        $placements = ServerSshKey::query()
            ->where('ssh_key_id', $sshKey->id)
            ->whereIn('server_id', $team->servers()->select('id'))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('server_id', $serverIds)->where('system_user', config('server.user')))
                ->orWhereIn('site_id', $siteIds))
            ->with('server')
            ->get();

        foreach ($placements as $placement) {
            RevokeSshKeyJob::dispatch($sshKey, $placement->server, $placement->system_user);

            $placement->update(['status' => 'revoking']);
        }

        return redirect()
            ->route('ssh-keys.index', $team)
            ->with('success', 'SSH key revocation initiated.');
    }

    public function servers(): Response
    {
        $servers = auth()->user()
            ->currentTeamOrFail()
            ->servers()
            ->where('status', 'active')
            ->get();

        return Inertia::render('ssh-keys/servers', [
            'servers' => ServerResource::collection($servers),
        ]);
    }
}
