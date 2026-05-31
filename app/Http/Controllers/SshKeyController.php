<?php

namespace App\Http\Controllers;

use App\Actions\SshKeys\SyncSshKeyAction;
use App\Http\Requests\StoreSshKeyRequest;
use App\Http\Requests\SyncSshKeyRequest;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SshKeyResource;
use App\Jobs\RevokeSshKeyJob;
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
            ->get();

        return Inertia::render('ssh-keys/index', [
            'sshKeys' => SshKeyResource::collection($sshKeys),
            'servers' => ServerResource::collection($servers),
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

        // Revoke from all servers first
        foreach ($sshKey->servers as $server) {
            RevokeSshKeyJob::dispatch($sshKey, $server);
        }

        $sshKey->delete();

        return redirect()
            ->route('ssh-keys.index', $team)
            ->with('success', 'SSH key deleted successfully.');
    }

    public function sync(Team $team, SshKey $sshKey, SyncSshKeyRequest $request, SyncSshKeyAction $action): RedirectResponse
    {
        $this->authorize('sync', $sshKey);

        $action->execute($sshKey, $request->validated('server_ids'));

        return redirect()
            ->route('ssh-keys.index', $team)
            ->with('success', 'SSH key sync initiated.');
    }

    public function revoke(Team $team, SshKey $sshKey, SyncSshKeyRequest $request): RedirectResponse
    {
        $this->authorize('revoke', $sshKey);

        $serverIds = $request->validated('server_ids');
        $servers = $team->servers()->whereIn('id', $serverIds)->get();

        foreach ($servers as $server) {
            RevokeSshKeyJob::dispatch($sshKey, $server);

            // Update pivot status
            $sshKey->servers()->updateExistingPivot($server->id, [
                'status' => 'revoking',
            ]);
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
