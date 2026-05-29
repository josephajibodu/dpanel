<?php

namespace App\Services\Webhook;

use App\Contracts\Webhook\DeploymentWebhookHandler;
use Illuminate\Http\Request;

class CustomDeploymentWebhookHandler implements DeploymentWebhookHandler
{
    /**
     * Custom Git providers have no standardised signature mechanism.
     * The secret in the URL is sufficient authentication.
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        return true;
    }

    /**
     * No standard payload format for custom providers — always deploy.
     */
    public function parseBranch(Request $request): ?string
    {
        return null;
    }
}
