<?php

namespace App\Services\Webhook;

use App\Contracts\Webhook\DeploymentWebhookHandler;
use Illuminate\Http\Request;

class GitlabDeploymentWebhookHandler implements DeploymentWebhookHandler
{
    /**
     * GitLab sends the configured secret token verbatim in X-Gitlab-Token.
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        return hash_equals($secret, (string) $request->header('X-Gitlab-Token', ''));
    }

    /**
     * GitLab push payloads include a `ref` field in the format `refs/heads/{branch}`.
     */
    public function parseBranch(Request $request): ?string
    {
        $ref = $request->json('ref');

        if (! is_string($ref)) {
            return null;
        }

        return str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : $ref;
    }
}
