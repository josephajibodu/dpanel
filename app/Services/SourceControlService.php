<?php

namespace App\Services;

use App\Enums\RepositoryProvider;
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
     * Ensure a deploy key exists for the given site / repository on the provider.
     *
     * This uses the server's local public key as the deploy key, allowing the server
     * to clone the repository via SSH without additional credentials.
     */
    public function ensureDeployKey(Site $site): void
    {
        $account = $site->sourceControlAccount;

        if (! $account) {
            return;
        }

        if ($account->provider !== RepositoryProvider::Github) {
            // Only GitHub is supported for deploy keys for now.
            return;
        }

        $server = $site->server;

        if (! $server || ! $server->local_public_key) {
            // Nothing we can do without a server public key.
            return;
        }

        if (! $site->repository) {
            return;
        }

        $websiteName = config('app.name');
        $this->createGithubDeployKey(
            account: $account,
            repositoryFullName: $site->repository,
            publicKey: $server->local_public_key,
            title: "$websiteName ($server->name)"
        );
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

    private function createGithubDeployKey(
        SourceControlAccount $account,
        string $repositoryFullName,
        string $publicKey,
        string $title,
    ): void {
        try {
            $appName = (string) config('app.name');
            $publicKeyTrimmed = trim($publicKey);

            // Check if deploy key already exists
            if ($this->deployKeyExists($account, $repositoryFullName, $publicKeyTrimmed)) {
                Log::info('GitHub deploy key already exists', [
                    'account_id' => $account->id,
                    'repository' => $repositoryFullName,
                ]);

                return;
            }

            $response = Http::withToken($account->token)
                ->acceptJson()
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post("https://api.github.com/repos/{$repositoryFullName}/keys", [
                    'title' => $title ?: $appName,
                    'key' => $publicKeyTrimmed,
                    'read_only' => true,
                ]);

            if ($response->status() === 201) {
                Log::info('GitHub deploy key created', [
                    'account_id' => $account->id,
                    'repository' => $repositoryFullName,
                ]);

                return;
            }

            // If the key already exists, GitHub may return 422; log and continue.
            Log::warning('Failed to create GitHub deploy key', [
                'account_id' => $account->id,
                'repository' => $repositoryFullName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception while creating GitHub deploy key', [
                'account_id' => $account->id,
                'repository' => $repositoryFullName,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a deploy key already exists for the given repository.
     */
    private function deployKeyExists(
        SourceControlAccount $account,
        string $repositoryFullName,
        string $publicKey,
    ): bool {
        try {
            $response = Http::withToken($account->token)
                ->acceptJson()
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->get("https://api.github.com/repos/{$repositoryFullName}/keys", [
                    'per_page' => 100,
                ]);

            if ($response->failed()) {
                return false;
            }

            /** @var array<int, array<string, mixed>> $keys */
            $keys = $response->json();

            $publicKeyNormalized = trim(str_replace(["\r\n", "\r", "\n"], '', $publicKey));

            foreach ($keys as $key) {
                $existingKeyNormalized = trim(str_replace(["\r\n", "\r", "\n"], '', (string) ($key['key'] ?? '')));

                if ($existingKeyNormalized === $publicKeyNormalized) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('Exception while checking for existing deploy key', [
                'account_id' => $account->id,
                'repository' => $repositoryFullName,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
