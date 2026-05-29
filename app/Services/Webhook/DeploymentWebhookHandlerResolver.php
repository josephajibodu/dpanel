<?php

namespace App\Services\Webhook;

use App\Contracts\Webhook\DeploymentWebhookHandler;
use App\Enums\RepositoryProvider;

class DeploymentWebhookHandlerResolver
{
    public function resolve(?RepositoryProvider $provider): DeploymentWebhookHandler
    {
        return match ($provider) {
            RepositoryProvider::Github => app(GithubDeploymentWebhookHandler::class),
            RepositoryProvider::Gitlab => app(GitlabDeploymentWebhookHandler::class),
            RepositoryProvider::Bitbucket => app(BitbucketDeploymentWebhookHandler::class),
            default => app(CustomDeploymentWebhookHandler::class),
        };
    }
}
