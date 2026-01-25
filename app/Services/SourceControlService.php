<?php

namespace App\Services;

use App\Enums\RepositoryProvider;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SourceControlService
{
    /**
     * List repositories available for a given source control account.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function listRepositories(SourceControlAccount $account): Collection
    {
        return match ($account->provider) {
            RepositoryProvider::Github => $this->listGithubRepositories($account),
            // @todo Implement GitLab and Bitbucket repositories when needed.
            default => collect(),
        };
    }

    /**
     * List branches for a given repository.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function listBranches(SourceControlAccount $account, string $repositoryFullName): Collection
    {
        return match ($account->provider) {
            RepositoryProvider::Github => $this->listGithubBranches($account, $repositoryFullName),
            // @todo Implement GitLab and Bitbucket branches when needed.
            default => collect(),
        };
    }

    /**
     * Ensure an account-level SSH key exists for the given server and source control account.
     *
     * This uses the server's local public key as an SSH key on the GitHub account,
     * allowing the server to clone any repository the account has access to via SSH.
     * The key is added once per server/account combination.
     */
    public function ensureAccountSshKey(Server $server, SourceControlAccount $account): void
    {
        if ($account->provider !== RepositoryProvider::Github) {
            // Only GitHub is supported for account-level SSH keys for now.
            return;
        }

        if (! $server->local_public_key) {
            // Nothing we can do without a server public key.
            return;
        }

        $websiteName = config('app.name');
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
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
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
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
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

        $accounts = SourceControlAccount::where('provider', RepositoryProvider::Github)
            ->whereIn('id', $accountIds)
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->deleteGithubAccountSshKey($account, $server->local_public_key);
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
        if ($account->provider !== RepositoryProvider::Github) {
            // Only GitHub is supported for account-level SSH keys for now.
            return;
        }

        if (! $server->local_public_key) {
            return;
        }

        // Check if any other sites on this server use this source control account
        $otherSitesUsingAccount = Site::where('server_id', $server->id)
            ->where('source_control_account_id', $account->id)
            ->exists();

        if ($otherSitesUsingAccount) {
            Log::info('Not deleting GitHub account SSH key - other sites on server still use this account', [
                'server_id' => $server->id,
                'account_id' => $account->id,
            ]);

            return;
        }

        $this->deleteGithubAccountSshKey(
            account: $account,
            publicKey: $server->local_public_key,
        );
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
