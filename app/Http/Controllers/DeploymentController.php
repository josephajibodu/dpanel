<?php

namespace App\Http\Controllers;

use App\Actions\Sites\CancelDeploymentAction;
use App\Actions\Sites\TriggerDeploymentAction;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    /**
     * Display a listing of deployments for a site.
     */
    public function index(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $deployments = $site->deployments()
            ->with('user')
            ->latest()
            ->paginate(20);

        return Inertia::render('sites/deployments/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site->load('server')),
            'deployments' => DeploymentResource::collection($deployments),
        ]);
    }

    /**
     * Store a newly created deployment (trigger deployment).
     */
    public function store(Request $request, Team $team, Server $server, Site $site, TriggerDeploymentAction $triggerDeployment): RedirectResponse
    {
        $this->authorize('view', $site);

        try {
            $deployment = $triggerDeployment->execute($site, 'manual', $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['deploy' => $e->getMessage()]);
        }

        return redirect()
            ->route('servers.sites.deployments.show', [$team, $server, $site, $deployment])
            ->with('success', 'Deployment started successfully.')
            ->with('deployment_started', [
                'commit' => $deployment->commit_hash ? substr($deployment->commit_hash, 0, 7) : (string) $deployment->id,
                'site' => $site->domain,
            ]);
    }

    /**
     * Cancel a pending deployment.
     */
    public function cancel(Team $team, Server $server, Site $site, Deployment $deployment, CancelDeploymentAction $cancelDeployment): RedirectResponse
    {
        $this->authorize('view', $site);

        try {
            $cancelDeployment->execute($deployment);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return back()->with('success', 'Deployment cancelled.');
    }

    /**
     * Display the specified deployment.
     */
    public function show(Team $team, Server $server, Site $site, Deployment $deployment): Response
    {
        $deployment->load(['site.server', 'user', 'logs']);

        $this->authorize('view', $deployment->site);

        $site = $deployment->site;

        $deploymentResource = new DeploymentResource($deployment);
        $deploymentResource->wrap(null);

        return Inertia::render('deployments/show', [
            'deployment' => $deploymentResource,
            'server' => new ServerResource($server),
            'site' => new SiteResource($site->load('server')),
            'logs' => $deployment->logs()->orderBy('created_at')->get()->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->type,
                'message' => $log->message,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
        ]);
    }
}
