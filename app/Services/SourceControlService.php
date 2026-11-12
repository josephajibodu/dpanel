<?php

namespace App\Services;

use App\Contracts\SourceControlClientContract;
use App\Enums\RepositoryProvider;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Services\SourceControl\BitbucketClient;
use App\Services\SourceControl\GitlabClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SourceControlService
{
    /**
     * List repositories available for a given source control account.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listRepositories(SourceControlAccount $account): Collection
    {
        return match ($account->provider) {
            RepositoryProvider::Github => $this->listGithubRepositories($account),
            default => $this->client($account->provider)?->listRepositories($account) ?? collect(),
        };
    }

    /**
     * List branches for a given repository.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listBranches(SourceControlAccount $account, string $repositoryFullName): Collection
    {
        return match ($account->provider) {
            RepositoryProvider::Github => $this->listGithubBranches($account, $repositoryFullName),
            default => $this->client($account->provider)?->listBranches($account, $repositoryFullName) ?? collect(),
        };
    }

    /**
     * Names of the files and folders at the top of a repository, on one
     * branch. Empty when the repository or branch can't be read.
     *
     * @return array<int, string>
     */
    public function listRootEntries(SourceControlAccount $account, string $repositoryFullName, string $branch): array
    {
        if ($account->provider !== RepositoryProvider::Github) {
            return $this->client($account->provider)?->listRootEntries($account, $repositoryFullName, $branch) ?? [];
        }

        $response = $this->github($account)->get("https://api.github.com/repos/{$repositoryFullName}/contents", ['ref' => $branch]);

        return $response->successful() ? $response->collect()->pluck('name')->all() : [];
    }

    /**
     * What one file in a repository holds on one branch, or null when the
     * file isn't there or can't be read.
     */
    public function readFile(SourceControlAccount $account, string $repositoryFullName, string $branch, string $path): ?string
    {
        if ($account->provider !== RepositoryProvider::Github) {
            return $this->client($account->provider)?->readFile($account, $repositoryFullName, $branch, $path);
        }

        $response = $this->github($account)
            ->accept('application/vnd.github.raw+json')
            ->get("https://api.github.com/repos/{$repositoryFullName}/contents/{$path}", ['ref' => $branch]);

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Ensure an account-level SSH key exists for the given server and source control account.
     *
     * This uses the server's local public key as an SSH key on the account,
     * allowing the server to clone any repository the account has access to via SSH.
     * The key is added once per server/account combination.
     */
    public function ensureAccountSshKey(Server $server, SourceControlAccount $account): void
    {
        if (! $server->local_public_key) {
            // Nothing we can do without a server public key.
            return;
        }

        $websiteName = config('app.name');

        if ($account->provider !== RepositoryProvider::Github) {
            try {
                $this->client($account->provider)?->addSshKey($account, $server->local_public_key, "$websiteName ($server->name)");
            } catch (\Throwable $e) {
                Log::error('Exception while adding account SSH key', ['account_id' => $account->id, 'message' => $e->getMessage()]);
            }

            return;
        }

        $this->createGithubAccountSshKey(
            account: $account,
            publicKey: $server->local_public_key,
            title: "$websiteName ($server->name)"
        );
    }

    /**
     * Ensure a deploy key exists for the given site / repository on the provider.
     *
     * @deprecated Use ensureAccountSshKey() instead for account-level keys
     */
    public function ensureDeployKey(Site $site): void
    {
        $account = $site->sourceControlAccount;

        if (! $account) {
            return;
        }

        $server = $site->server;

        if (! $server) {
            return;
        }

        // Use account-level SSH key instead of repository deploy key
        $this->ensureAccountSshKey($server, $account);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function listGithubRepositories(SourceControlAccount $account): Collection
    {
        try {
            $allRepos = collect();
            $page = 1;
            $perPage = 100;

            do {
                $response = Http::withToken($account->token)
                    ->acceptJson()
                    ->withHeaders([
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->get('https://api.github.com/user/repos', [
                        'per_page' => $perPage,
                        'page' => $page,
                        'sort' => 'full_name',
                        'direction' => 'asc',
                    ]);

                if ($response->failed()) {
                    Log::warning('Failed to fetch GitHub repositories', [
                        'account_id' => $account->id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    break;
                }

                /** @var array<int, array<string, mixed>> $repos */
                $repos = $response->json();

                if (empty($repos)) {
                    break;
                }

                $allRepos = $allRepos->merge($repos);
                $page++;
            } while (count($repos) === $perPage);

            return $allRepos->map(function (array $repo): array {
                return [
                    'id' => $repo['id'],
                    'name' => $repo['name'],
                    'full_name' => $repo['full_name'],
                    'ssh_url' => $repo['ssh_url'],
                    'html_url' => $repo['html_url'],
                    'default_branch' => $repo['default_branch'] ?? 'main',
                    'private' => (bool) ($repo['private'] ?? false),
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Exception while fetching GitHub repositories', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function listGithubBranches(SourceControlAccount $account, string $repositoryFullName): Collection
    {
        try {
            $allBranches = collect();
            $page = 1;
            $perPage = 100;

            do {
                $response = Http::withToken($account->token)
                    ->acceptJson()
                    ->withHeaders([
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->get("https://api.github.com/repos/{$repositoryFullName}/branches", [
                        'per_page' => $perPage,
                        'page' => $page,
                    ]);

                if ($response->failed()) {
                    Log::warning('Failed to fetch GitHub branches', [
                        'account_id' => $account->id,
                        'repository' => $repositoryFullName,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    break;
                }

                /** @var array<int, array<string, mixed>> $branches */
                $branches = $response->json();

                if (empty($branches)) {
                    break;
                }

                $allBranches = $allBranches->merge($branches);
                $page++;
            } while (count($branches) === $perPage);

            return $allBranches->map(function (array $branch): array {
                return [
                    'name' => $branch['name'],
                    'protected' => (bool) ($branch['protected'] ?? false),
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Exception while fetching GitHub branches', [
                'account_id' => $account->id,
                'repository' => $repositoryFullName,
                'message' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Create an account-level SSH key on GitHub for the given account.
     */
    private function createGithubAccountSshKey(
        SourceControlAccount $account,
        string $publicKey,
        string $title,
    ): void {
        try {
            $appName = (string) config('app.name');
            $publicKeyTrimmed = trim($publicKey);

            // Check if SSH key already exists on the account
            if ($this->accountSshKeyExists($account, $publicKeyTrimmed)) {
                Log::info('GitHub account SSH key already exists', [
                    'account_id' => $account->id,
                ]);

                return;
            }

            $response = Http::withToken($account->token)
                ->acceptJson()
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post('https://api.github.com/user/keys', [
                    'title' => $title ?: $appName,
                    'key' => $publicKeyTrimmed,
                ]);

            if ($response->status() === 201) {
                Log::info('GitHub account SSH key created', [
                    'account_id' => $account->id,
                    'key_id' => $response->json()['id'] ?? null,
                ]);

                return;
            }

            // Handle specific error cases
            if ($response->status() === 404 || $response->status() === 403) {
                Log::error('GitHub account SSH key creation failed - missing permissions. The OAuth token may need the "admin:public_key" scope. User should reconnect their GitHub account.', [
                    'account_id' => $account->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            // If the key already exists, GitHub may return 422; log and continue.
            Log::warning('Failed to create GitHub account SSH key', [
                'account_id' => $account->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception while creating GitHub account SSH key', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if an SSH key already exists on the GitHub account.
     */
    private function accountSshKeyExists(
        SourceControlAccount $account,
        string $publicKey,
    ): bool {
        try {
            $allKeys = collect();
            $page = 1;
            $perPage = 100;

            do {
                $response = Http::withToken($account->token)
                    ->acceptJson()
                    ->withHeaders([
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->get('https://api.github.com/user/keys', [
                        'per_page' => $perPage,
                        'page' => $page,
                    ]);

                if ($response->failed()) {
                    return false;
                }

                /** @var array<int, array<string, mixed>> $keys */
                $keys = $response->json();

                if (empty($keys)) {
                    break;
                }

                $allKeys = $allKeys->merge($keys);
                $page++;
            } while (count($keys) === $perPage);

            $publicKeyNormalized = trim(str_replace(["\r\n", "\r", "\n"], '', $publicKey));

            foreach ($allKeys as $key) {
                $existingKeyNormalized = trim(str_replace(["\r\n", "\r", "\n"], '', (string) ($key['key'] ?? '')));

                if ($existingKeyNormalized === $publicKeyNormalized) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('Exception while checking for existing account SSH key', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete all account-level SSH keys for a server from all GitHub accounts.
     * Used when a server is being deleted.
     */
    public function deleteAllAccountSshKeysForServer(Server $server): void
    {
        if (! $server->local_public_key) {
            return;
        }

        // Get all unique GitHub source control accounts that have sites on this server
        $accountIds = Site::where('server_id', $server->id)
            ->whereNotNull('source_control_account_id')
            ->distinct()
            ->pluck('source_control_account_id');

        $accounts = SourceControlAccount::whereIn('id', $accountIds)->get();

        foreach ($accounts as $account) {
            try {
                $this->deleteAccountSshKey($account, $server->local_public_key);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete SSH key for account during server deletion', [
                    'server_id' => $server->id,
                    'account_id' => $account->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete an account-level SSH key from GitHub if no other sites on the server use that account.
     */
    public function deleteAccountSshKeyIfUnused(Server $server, SourceControlAccount $account): void
    {
        if (! $server->local_public_key) {
            return;
        }

        // Check if any other sites on this server use this source control account
        $otherSitesUsingAccount = Site::where('server_id', $server->id)
            ->where('source_control_account_id', $account->id)
            ->exists();

        if ($otherSitesUsingAccount) {
            Log::info('Not deleting account SSH key - other sites on server still use this account', [
                'server_id' => $server->id,
                'account_id' => $account->id,
            ]);

            return;
        }

        $this->deleteAccountSshKey($account, $server->local_public_key);
    }

    private function deleteAccountSshKey(SourceControlAccount $account, string $publicKey): void
    {
        if ($account->provider === RepositoryProvider::Github) {
            $this->deleteGithubAccountSshKey($account, $publicKey);

            return;
        }

        $this->client($account->provider)?->deleteSshKey($account, $publicKey);
    }

    /**
     * The API client for a Git host other than GitHub; null for GitHub
     * (handled in this class) and for hosts FlitOps has no API for.
     */
    private function client(?RepositoryProvider $provider): ?SourceControlClientContract
    {
        return match ($provider) {
            RepositoryProvider::Gitlab => app(GitlabClient::class),
            RepositoryProvider::Bitbucket => app(BitbucketClient::class),
            default => null,
        };
    }

    /**
     * Whether pushes to this site's repository can be wired to deploy it.
     */
    public function supportsDeployWebhooks(Site $site): bool
    {
        return $site->repository !== null
            && $site->sourceControlAccount !== null
            && ($site->repository_provider === RepositoryProvider::Github || $this->client($site->repository_provider) !== null);
    }

    /**
     * Register a push webhook on the site's repository that calls the site's
     * deployment webhook URL, signed with the site's webhook secret.
     *
     * @return string The provider's id for the webhook.
     *
     * @throws RuntimeException when the provider refuses, with a message fit to show the user.
     */
    public function createDeployWebhook(Site $site): string
    {
        $client = $this->client($site->repository_provider);

        if ($client !== null) {
            return $client->createDeployWebhook($site);
        }

        $response = $this->github($site->sourceControlAccount)
            ->post("https://api.github.com/repos/{$site->repository}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => [
                    'url' => route('deployment-webhook.handle', $site->webhook_secret),
                    'content_type' => 'json',
                    'secret' => $site->webhook_secret,
                    'insecure_ssl' => '0',
                ],
            ]);

        if ($response->status() === 201) {
            return (string) $response->json('id');
        }

        Log::warning('Failed to create GitHub deploy webhook', [
            'site_id' => $site->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new RuntimeException(match ($response->status()) {
            403, 404 => "GitHub refused to add a webhook to {$site->repository}. You need admin access to the repository; if you have it, reconnect your GitHub account and try again.",
            default => 'GitHub could not add the webhook. Try again in a moment.',
        });
    }

    /**
     * Remove the site's push webhook from its repository. A webhook that's
     * already gone (deleted on GitHub, or the repository removed) counts as removed.
     *
     * @throws RuntimeException when the provider refuses, with a message fit to show the user.
     */
    public function deleteDeployWebhook(Site $site): void
    {
        if ($site->deploy_webhook_id === null || $site->sourceControlAccount === null) {
            return;
        }

        $client = $this->client($site->repository_provider);

        if ($client !== null) {
            $client->deleteDeployWebhook($site);

            return;
        }

        $response = $this->github($site->sourceControlAccount)
            ->delete("https://api.github.com/repos/{$site->repository}/hooks/{$site->deploy_webhook_id}");

        if ($response->successful() || $response->status() === 404) {
            return;
        }

        Log::warning('Failed to delete GitHub deploy webhook', [
            'site_id' => $site->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new RuntimeException('GitHub could not remove the webhook. Try again in a moment.');
    }

    private function github(SourceControlAccount $account): PendingRequest
    {
        return Http::withToken($account->token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15);
    }

    /**
     * Delete a deploy key from a repository.
     *
     * @deprecated Use deleteAccountSshKeyIfUnused() instead for account-level keys
     */
    public function deleteDeployKey(Site $site): void
    {
        $account = $site->sourceControlAccount;

        if (! $account) {
            return;
        }

        $server = $site->server;

        if (! $server) {
            return;
        }

        // Use account-level SSH key deletion instead
        $this->deleteAccountSshKeyIfUnused($server, $account);
    }

    /**
     * Delete an account-level SSH key from GitHub by matching the public key.
     */
    private function deleteGithubAccountSshKey(
        SourceControlAccount $account,
        string $publicKey,
    ): void {
        try {
            // First, find the SSH key ID by matching the public key
            $allKeys = collect();
            $page = 1;
            $perPage = 100;

            do {
                $response = Http::withToken($account->token)
                    ->acceptJson()
                    ->withHeaders([
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->get('https://api.github.com/user/keys', [
                        'per_page' => $perPage,
                        'page' => $page,
                    ]);

                if ($response->failed()) {
                    Log::warning('Failed to list GitHub account SSH keys for deletion', [
                        'account_id' => $account->id,
                        'status' => $response->status(),
                    ]);

                    return;
                }

                /** @var array<int, array<string, mixed>> $keys */
                $keys = $response->json();

                if (empty($keys)) {
                    break;
                }

                $allKeys = $allKeys->merge($keys);
                $page++;
            } while (count($keys) === $perPage);

            $publicKeyNormalized = trim(str_replace(["\r\n", "\r", "\n"], '', $publicKey));

            foreach ($allKeys as $key) {
                $existingKeyNormalized = trim(str_replace(["\r\n", "\r", "\n"], '', (string) ($key['key'] ?? '')));

                if ($existingKeyNormalized === $publicKeyNormalized) {
                    $keyId = $key['id'] ?? null;

                    if (! $keyId) {
                        continue;
                    }

                    // Delete the SSH key
                    $deleteResponse = Http::withToken($account->token)
                        ->acceptJson()
                        ->withHeaders([
                            'X-GitHub-Api-Version' => '2022-11-28',
                        ])
                        ->delete("https://api.github.com/user/keys/{$keyId}");

                    if ($deleteResponse->status() === 204) {
                        Log::info('GitHub account SSH key deleted', [
                            'account_id' => $account->id,
                            'key_id' => $keyId,
                        ]);
                    } else {
                        Log::warning('Failed to delete GitHub account SSH key', [
                            'account_id' => $account->id,
                            'key_id' => $keyId,
                            'status' => $deleteResponse->status(),
                            'body' => $deleteResponse->body(),
                        ]);
                    }

                    return;
                }
            }

            Log::info('GitHub account SSH key not found (may have been deleted already)', [
                'account_id' => $account->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception while deleting GitHub account SSH key', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
