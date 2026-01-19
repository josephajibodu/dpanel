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

        $this->createGithubDeployKey(
            account: $account,
            repositoryFullName: $site->repository,
            publicKey: $server->local_public_key,
            title: 'ServerForge - '.$server->name
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function listGithubRepositories(SourceControlAccount $account): Collection
    {
        try {
            $response = Http::withToken($account->token)
                ->acceptJson()
                ->get('https://api.github.com/user/repos', [
                    'per_page' => 100,
                    'sort' => 'full_name',
                    'direction' => 'asc',
                ]);

            if ($response->failed()) {
                Log::warning('Failed to fetch GitHub repositories', [
                    'account_id' => $account->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return collect();
            }

            /** @var array<int, array<string, mixed>> $repos */
            $repos = $response->json();

            return collect($repos)->map(function (array $repo): array {
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

            $response = Http::withToken($account->token)
                ->acceptJson()
                ->post("https://api.github.com/repos/{$repositoryFullName}/keys", [
                    'title' => $title ?: $appName,
                    'key' => trim($publicKey),
                    'read_only' => true,
                ]);

            if ($response->successful()) {
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
}

