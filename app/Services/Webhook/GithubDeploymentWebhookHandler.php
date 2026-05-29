<?php

namespace App\Services\Webhook;

use App\Contracts\Webhook\DeploymentWebhookHandler;
use Illuminate\Http\Request;

class GithubDeploymentWebhookHandler implements DeploymentWebhookHandler
{
    /**
     * GitHub sends an HMAC-SHA256 signature of the raw body in X-Hub-Signature-256.
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        $header = $request->header('X-Hub-Signature-256');

        if (! $header) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * GitHub push payloads include a `ref` field in the format `refs/heads/{branch}`.
     */
    public function parseBranch(Request $request): ?string
    {
        return $this->parseRef($request->json('ref'));
    }

    private function parseRef(mixed $ref): ?string
    {
        if (! is_string($ref)) {
            return null;
        }

        return str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : $ref;
    }
}
