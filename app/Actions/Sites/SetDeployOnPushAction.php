<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\SourceControlService;
use RuntimeException;

/**
 * Turns deploy-on-push on or off for a site: a push webhook is added to, or
 * removed from, its repository, and `auto_deploy` records the result. The
 * provider is called first, so the site only says deploys are automatic once
 * the webhook really exists.
 */
class SetDeployOnPushAction
{
    public function __construct(private SourceControlService $sourceControl) {}

    /**
     * @throws RuntimeException when the repository's provider refuses, with a message fit to show the user.
     */
    public function execute(Site $site, bool $enabled): void
    {
        if ($enabled === $site->auto_deploy && ($enabled === ($site->deploy_webhook_id !== null))) {
            return;
        }

        if (! $enabled) {
            $this->sourceControl->deleteDeployWebhook($site);
            $site->update(['auto_deploy' => false, 'deploy_webhook_id' => null]);

            return;
        }

        if (! $this->sourceControl->supportsDeployWebhooks($site)) {
            throw new RuntimeException('Deploy on push needs a repository from a connected GitHub, GitLab or Bitbucket account.');
        }

        $webhookId = $this->sourceControl->createDeployWebhook($site);
        $site->update(['auto_deploy' => true, 'deploy_webhook_id' => $webhookId]);
    }
}
