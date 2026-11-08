<?php

namespace App\Services\Webhook;

use App\Contracts\Webhook\DeploymentWebhookHandler;
use Illuminate\Http\Request;

class BitbucketDeploymentWebhookHandler implements DeploymentWebhookHandler
{
    /**
     * Bitbucket names the event in X-Event-Key. A push that deletes a branch
     * has no new branch, so it doesn't count.
     */
    public function isPush(Request $request): bool
    {
        return $request->header('X-Event-Key') === 'repo:push'
            && $request->json('push.changes.0.new.type') === 'branch';
    }

    /**
     * FlitOps registers Bitbucket webhooks with the site's secret, so each
     * request carries an HMAC-SHA256 of its body in X-Hub-Signature.
     */
    public function verifySignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Hub-Signature', '');

        return $header !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }

    /**
     * Bitbucket push payloads nest the branch name under push.changes[].new.name.
     */
    public function parseBranch(Request $request): ?string
    {
        return $request->json('push.changes.0.new.name');
    }
}
