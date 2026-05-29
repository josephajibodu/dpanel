<?php

namespace App\Services\Webhook;

use App\Contracts\Webhook\DeploymentWebhookHandler;
use Illuminate\Http\Request;

class BitbucketDeploymentWebhookHandler implements DeploymentWebhookHandler
{
    /**
     * Bitbucket's standard plan does not include request signatures,
     * so the secret in the URL is the only authentication layer.
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        return true;
    }

    /**
     * Bitbucket push payloads nest the branch name under push.changes[].new.name.
     */
    public function parseBranch(Request $request): ?string
    {
        return $request->json('push.changes.0.new.name');
    }
}
