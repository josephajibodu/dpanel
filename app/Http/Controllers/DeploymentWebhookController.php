<?php

namespace App\Http\Controllers;

use App\Actions\Sites\TriggerDeploymentAction;
use App\Models\Site;
use App\Services\Webhook\DeploymentWebhookHandlerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentWebhookController extends Controller
{
    public function __construct(
        private DeploymentWebhookHandlerResolver $resolver,
        private TriggerDeploymentAction $triggerDeployment,
    ) {}

    public function handle(Request $request, string $secret): JsonResponse
    {
        $site = Site::where('webhook_secret', $secret)->first();

        if (! $site) {
            abort(404);
        }

        if (! $site->auto_deploy) {
            return response()->json(['message' => 'Auto-deploy is disabled for this site.']);
        }

        $handler = $this->resolver->resolve($site->repository_provider);

        if (! $handler->verifySignature($request, $secret)) {
            abort(403, 'Invalid webhook signature.');
        }

        $pushedBranch = $handler->parseBranch($request);

        if ($pushedBranch !== null && $pushedBranch !== $site->branch) {
            return response()->json(['message' => 'Branch does not match. Deployment skipped.']);
        }

        try {
            $this->triggerDeployment->execute($site, 'webhook');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Deployment triggered.']);
    }
}
