<?php

namespace App\Contracts\Webhook;

use Illuminate\Http\Request;

interface DeploymentWebhookHandler
{
    /**
     * Verify the provider-specific request signature.
     * Return true if the request is authentic, false otherwise.
     */
    public function verifySignature(Request $request, string $secret): bool;

    /**
     * Parse the pushed branch name from the request payload.
     * Return null when the branch cannot be determined — the caller treats this as a match.
     */
    public function parseBranch(Request $request): ?string;

    /**
     * Whether the request reports a push. Providers also send other events
     * (GitHub pings a webhook as soon as it's created), which must not deploy.
     */
    public function isPush(Request $request): bool;
}
