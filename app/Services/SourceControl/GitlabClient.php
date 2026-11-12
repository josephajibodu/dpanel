<?php

namespace App\Services\SourceControl;

use App\Contracts\SourceControlClientContract;
use App\Models\Site;
use App\Models\SourceControlAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GitLab's REST API (v4), on gitlab.com or the host in services.gitlab.host.
 */
class GitlabClient implements SourceControlClientContract
{
    use RefreshesOAuthTokens;

    /**
     * Pages of 100 to fetch at most from one list, so a misbehaving
     * response can't keep the request going forever.
     */
    private const MAX_PAGES = 50;

    public function listRepositories(SourceControlAccount $account): Collection
    {
        return $this->paginate($account, '/projects', ['membership' => 'true', 'simple' => 'true', 'order_by' => 'path', 'sort' => 'asc'])
            ->map(fn (array $project): array => [
                'id' => (string) $project['id'],
                'name' => (string) $project['name'],
                'full_name' => (string) $project['path_with_namespace'],
                'ssh_url' => (string) $project['ssh_url_to_repo'],
                'html_url' => (string) $project['web_url'],
                'default_branch' => (string) ($project['default_branch'] ?? 'main'),
                'private' => ($project['visibility'] ?? 'private') !== 'public',
            ]);
    }

    public function listBranches(SourceControlAccount $account, string $repositoryFullName): Collection
    {
        return $this->paginate($account, "/projects/{$this->projectId($repositoryFullName)}/repository/branches")
            ->map(fn (array $branch): array => [
                'name' => (string) $branch['name'],
                'protected' => (bool) ($branch['protected'] ?? false),
            ]);
    }

    public function listEntries(SourceControlAccount $account, string $repositoryFullName, string $branch, string $folder = ''): array
    {
        $query = $folder === '' ? ['ref' => $branch] : ['ref' => $branch, 'path' => $folder];

        return $this->paginate($account, "/projects/{$this->projectId($repositoryFullName)}/repository/tree", $query)
            ->map(fn (array $entry): array => [
                'name' => (string) $entry['name'],
                'directory' => ($entry['type'] ?? null) === 'tree',
            ])
            ->all();
    }

    public function readFile(SourceControlAccount $account, string $repositoryFullName, string $branch, string $path): ?string
    {
        $response = $this->api($account)->get(
            "/projects/{$this->projectId($repositoryFullName)}/repository/files/".rawurlencode($path).'/raw',
            ['ref' => $branch],
        );

        return $response->successful() ? $response->body() : null;
    }

    public function addSshKey(SourceControlAccount $account, string $publicKey, string $title): void
    {
        if ($this->findSshKeyId($account, $publicKey) !== null) {
            return;
        }

        $response = $this->api($account)->post('/user/keys', ['title' => $title, 'key' => trim($publicKey)]);

        if (! $response->successful()) {
            Log::warning('Failed to add GitLab account SSH key', ['account_id' => $account->id, 'status' => $response->status(), 'body' => $response->body()]);
        }
    }

    public function deleteSshKey(SourceControlAccount $account, string $publicKey): void
    {
        $keyId = $this->findSshKeyId($account, $publicKey);

        if ($keyId !== null) {
            $this->api($account)->delete("/user/keys/{$keyId}");
        }
    }

    public function createDeployWebhook(Site $site): string
    {
        $response = $this->api($site->sourceControlAccount)
            ->post("/projects/{$this->projectId($site->repository)}/hooks", [
                'url' => route('deployment-webhook.handle', $site->webhook_secret),
                'token' => $site->webhook_secret,
                'push_events' => true,
                'push_events_branch_filter' => $site->branch,
                'enable_ssl_verification' => true,
            ]);

        if ($response->status() === 201) {
            return (string) $response->json('id');
        }

        Log::warning('Failed to create GitLab deploy webhook', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

        throw new RuntimeException(match ($response->status()) {
            401, 403, 404 => "GitLab refused to add a webhook to {$site->repository}. You need the Maintainer role on the project; if you have it, reconnect your GitLab account and try again.",
            default => 'GitLab could not add the webhook. Try again in a moment.',
        });
    }

    public function deleteDeployWebhook(Site $site): void
    {
        $response = $this->api($site->sourceControlAccount)
            ->delete("/projects/{$this->projectId($site->repository)}/hooks/{$site->deploy_webhook_id}");

        if ($response->successful() || $response->status() === 404) {
            return;
        }

        Log::warning('Failed to delete GitLab deploy webhook', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

        throw new RuntimeException('GitLab could not remove the webhook. Try again in a moment.');
    }

    protected function requestTokenRefresh(SourceControlAccount $account): Response
    {
        return Http::asForm()->timeout(15)->post($this->host().'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => config('services.gitlab.client_id'),
            'client_secret' => config('services.gitlab.client_secret'),
            'redirect_uri' => url(config('services.gitlab.redirect')),
        ]);
    }

    private function findSshKeyId(SourceControlAccount $account, string $publicKey): ?int
    {
        $wanted = $this->keyMaterial($publicKey);

        $key = $this->paginate($account, '/user/keys')
            ->first(fn (array $key): bool => $this->keyMaterial((string) ($key['key'] ?? '')) === $wanted);

        return $key === null ? null : (int) $key['id'];
    }

    /**
     * The key type and data, without the comment GitLab may store with it.
     */
    private function keyMaterial(string $publicKey): string
    {
        return implode(' ', array_slice(preg_split('/\s+/', trim($publicKey)) ?: [], 0, 2));
    }

    /**
     * A project's path, URL-encoded so GitLab accepts it in place of its id
     * (nested groups included).
     */
    private function projectId(string $repositoryFullName): string
    {
        return rawurlencode($repositoryFullName);
    }

    /**
     * Every page of a list endpoint. Stops quietly at the first failed page.
     *
     * @param  array<string, string>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function paginate(SourceControlAccount $account, string $path, array $query = []): Collection
    {
        $items = collect();
        $page = 1;

        do {
            $response = $this->api($account)->get($path, [...$query, 'per_page' => 100, 'page' => $page]);

            if (! $response->successful()) {
                Log::warning('GitLab request failed', ['account_id' => $account->id, 'path' => $path, 'status' => $response->status()]);

                break;
            }

            $items = $items->merge($response->json());
            $nextPage = (int) $response->header('X-Next-Page');
            $page = $nextPage > $page ? $nextPage : 0;
        } while ($page > 0 && $page <= self::MAX_PAGES);

        return $items;
    }

    private function api(SourceControlAccount $account): PendingRequest
    {
        return Http::baseUrl($this->host().'/api/v4')
            ->withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(15);
    }

    private function host(): string
    {
        return rtrim((string) config('services.gitlab.host', 'https://gitlab.com'), '/');
    }
}
