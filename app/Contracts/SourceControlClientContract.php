<?php

namespace App\Contracts;

use App\Models\Site;
use App\Models\SourceControlAccount;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * What FlitOps needs from a Git host besides GitHub, which
 * SourceControlService still talks to directly.
 */
interface SourceControlClientContract
{
    /**
     * Repositories the account can reach.
     *
     * @return Collection<int, array{id: string, name: string, full_name: string, ssh_url: string, html_url: string, default_branch: string, private: bool}>
     */
    public function listRepositories(SourceControlAccount $account): Collection;

    /**
     * @return Collection<int, array{name: string, protected: bool}>
     */
    public function listBranches(SourceControlAccount $account, string $repositoryFullName): Collection;

    /**
     * Names of the files and folders at the top of a repository, on one
     * branch. Empty when the repository or branch can't be read.
     *
     * @return array<int, string>
     */
    public function listRootEntries(SourceControlAccount $account, string $repositoryFullName, string $branch): array;

    /**
     * What one file in a repository holds on one branch, or null when the
     * file isn't there or can't be read.
     */
    public function readFile(SourceControlAccount $account, string $repositoryFullName, string $branch, string $path): ?string;

    /**
     * Add a server's public key to the account, so the server can clone the
     * account's repositories. Does nothing if the key is already there.
     */
    public function addSshKey(SourceControlAccount $account, string $publicKey, string $title): void;

    /**
     * Remove a server's public key from the account, if it's there.
     */
    public function deleteSshKey(SourceControlAccount $account, string $publicKey): void;

    /**
     * Register a push webhook on the site's repository that calls its
     * deployment webhook URL, authenticated with the site's webhook secret.
     *
     * @return string The provider's id for the webhook.
     *
     * @throws RuntimeException when the provider refuses, with a message fit to show the user.
     */
    public function createDeployWebhook(Site $site): string;

    /**
     * Remove the site's push webhook. A webhook that's already gone counts as removed.
     *
     * @throws RuntimeException when the provider refuses, with a message fit to show the user.
     */
    public function deleteDeployWebhook(Site $site): void;
}
