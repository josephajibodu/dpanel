<?php

namespace App\Http\Controllers;

use App\Actions\Sites\TriggerDeploymentAction;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    /**
     * Display a listing of deployments for a site.
     */
    public function index(Site $site): Response
    {
        $this->authorize('view', $site);

        $deployments = $site->deployments()
            ->with('user')
            ->latest()
            ->paginate(20);

        return Inertia::render('sites/deployments', [
            'site' => $site->load('server'),
            'deployments' => DeploymentResource::collection($deployments),
        ]);
    }

    /**
     * Store a newly created deployment (trigger deployment).
     */
    public function store(Request $request, Site $site, TriggerDeploymentAction $triggerDeployment): RedirectResponse
    {
        $this->authorize('view', $site);

        $deployment = $triggerDeployment->execute($site, 'manual');

        return redirect()
            ->route('deployments.show', $deployment)
            ->with('success', 'Deployment started successfully.');
    }

    /**
     * Display the specified deployment.
     */
    public function show(Deployment $deployment): Response
    {
        $deployment->load(['site.server', 'user', 'logs']);

        $this->authorize('view', $deployment->site);

        return Inertia::render('deployments/show', [
            'deployment' => new DeploymentResource($deployment),
            'site' => $deployment->site,
            'logs' => $deployment->logs()->orderBy('created_at')->get()->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->type,
                'message' => $log->message,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
        ]);
    }
}
