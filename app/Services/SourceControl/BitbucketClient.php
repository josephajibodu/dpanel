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
 * Bitbucket Cloud's REST API (2.0). The OAuth consumer's permissions decide
 * what's allowed: Account (Write) for SSH keys, Repositories (Read) and
 * Webhooks (Read and write).
 */
class BitbucketClient implements SourceControlClientContract
{
    use RefreshesOAuthTokens;

    private const API = 'https://api.bitbucket.org/2.0';

    /**
     * Pages of 100 to fetch at most from one list, so a misbehaving
     * response can't keep the request going forever.
     */
    private const MAX_PAGES = 50;

    public function listRepositories(SourceControlAccount $account): Collection
    {
        return $this->paginate($account, '/repositories', ['role' => 'member', 'sort' => 'full_name'])
            ->map(fn (array $repository): array => [
                'id' => (string) $repository['uuid'],
                'name' => (string) $repository['name'],
                'full_name' => (string) $repository['full_name'],
                'ssh_url' => (string) (collect($repository['links']['clone'] ?? [])->firstWhere('name', 'ssh')['href'] ?? "git@bitbucket.org:{$repository['full_name']}.git"),
                'html_url' => (string) ($repository['links']['html']['href'] ?? "https://bitbucket.org/{$repository['full_name']}"),
                'default_branch' => (string) ($repository['mainbranch']['name'] ?? 'main'),
                'private' => (bool) ($repository['is_private'] ?? true),
            ]);
    }

    public function listBranches(SourceControlAccount $account, string $repositoryFullName): Collection
    {
        return $this->paginate($account, "/repositories/{$repositoryFullName}/refs/branches")
            ->map(fn (array $branch): array => [
                'name' => (string) $branch['name'],
                'protected' => false,
            ]);
    }

    public function listRootEntries(SourceControlAccount $account, string $repositoryFullName, string $branch): array
    {
        $commit = $this->commitFor($account, $repositoryFullName, $branch);

        if ($commit === null) {
            return [];
        }

        return $this->paginate($account, "/repositories/{$repositoryFullName}/src/{$commit}/")
            ->map(fn (array $entry): string => (string) $entry['path'])
            ->all();
    }

    public function readFile(SourceControlAccount $account, string $repositoryFullName, string $branch, string $path): ?string
    {
        $commit = $this->commitFor($account, $repositoryFullName, $branch);

        if ($commit === null) {
            return null;
        }

        $response = $this->api($account)->get("/repositories/{$repositoryFullName}/src/{$commit}/{$path}");

        return $response->successful() ? $response->body() : null;
    }

    public function addSshKey(SourceControlAccount $account, string $publicKey, string $title): void
    {
        if ($this->findSshKeyId($account, $publicKey) !== null) {
            return;
        }

        $response = $this->api($account)->post($this->sshKeysPath($account), ['key' => trim($publicKey), 'label' => $title]);

        if (! $response->successful()) {
            Log::warning('Failed to add Bitbucket account SSH key', ['account_id' => $account->id, 'status' => $response->status(), 'body' => $response->body()]);
        }
    }

    public function deleteSshKey(SourceControlAccount $account, string $publicKey): void
    {
        $keyId = $this->findSshKeyId($account, $publicKey);

        if ($keyId !== null) {
            $this->api($account)->delete($this->sshKeysPath($account).'/'.rawurlencode($keyId));
        }
    }

    public function createDeployWebhook(Site $site): string
    {
        $response = $this->api($site->sourceControlAccount)
            ->post("/repositories/{$site->repository}/hooks", [
                'description' => config('app.name').' deploy on push',
                'url' => route('deployment-webhook.handle', $site->webhook_secret),
                'secret' => $site->webhook_secret,
                'active' => true,
                'events' => ['repo:push'],
            ]);

        if ($response->status() === 201) {
            return (string) $response->json('uuid');
        }

        Log::warning('Failed to create Bitbucket deploy webhook', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

        throw new RuntimeException(match ($response->status()) {
            401, 403, 404 => "Bitbucket refused to add a webhook to {$site->repository}. You need admin access to the repository; if you have it, reconnect your Bitbucket account and try again.",
            default => 'Bitbucket could not add the webhook. Try again in a moment.',
        });
    }

    public function deleteDeployWebhook(Site $site): void
    {
        $response = $this->api($site->sourceControlAccount)
            ->delete("/repositories/{$site->repository}/hooks/".rawurlencode((string) $site->deploy_webhook_id));

        if ($response->successful() || $response->status() === 404) {
            return;
        }

        Log::warning('Failed to delete Bitbucket deploy webhook', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

        throw new RuntimeException('Bitbucket could not remove the webhook. Try again in a moment.');
    }

    /**
     * What to put in a file URL to point at a branch. Bitbucket takes the
     * branch name there, unless it contains a slash: the URL would read the
     * rest as a file path, so the branch's latest commit is used instead.
     * Null when that commit can't be found.
     */
    private function commitFor(SourceControlAccount $account, string $repositoryFullName, string $branch): ?string
    {
        if (! str_contains($branch, '/')) {
            return $branch;
        }

        $hash = $this->api($account)
            ->get("/repositories/{$repositoryFullName}/refs/branches/{$branch}")
            ->json('target.hash');

        return is_string($hash) ? $hash : null;
    }

    protected function requestTokenRefresh(SourceControlAccount $account): Response
    {
        return Http::asForm()
            ->withBasicAuth((string) config('services.bitbucket.client_id'), (string) config('services.bitbucket.client_secret'))
            ->timeout(15)
            ->post('https://bitbucket.org/site/oauth2/access_token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);
    }

    private function findSshKeyId(SourceControlAccount $account, string $publicKey): ?string
    {
        $wanted = $this->keyMaterial($publicKey);

        $key = $this->paginate($account, $this->sshKeysPath($account))
            ->first(fn (array $key): bool => $this->keyMaterial((string) ($key['key'] ?? '')) === $wanted);

        return $key === null ? null : (string) $key['uuid'];
    }

    /**
     * The key type and data, without the comment Bitbucket may store with it.
     */
    private function keyMaterial(string $publicKey): string
    {
        return implode(' ', array_slice(preg_split('/\s+/', trim($publicKey)) ?: [], 0, 2));
    }

    /**
     * The connected user's SSH keys, addressed by their account UUID.
     */
    private function sshKeysPath(SourceControlAccount $account): string
    {
        return '/users/'.rawurlencode($account->provider_user_id).'/ssh-keys';
    }

    /**
     * Every page of a list endpoint, following Bitbucket's `next` links.
     * Stops quietly at the first failed page.
     *
     * @param  array<string, string>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function paginate(SourceControlAccount $account, string $path, array $query = []): Collection
    {
        $items = collect();
        $response = $this->api($account)->get($path, [...$query, 'pagelen' => 100]);
        $fetched = [];

        for ($pages = 1; $pages <= self::MAX_PAGES; $pages++) {
            if (! $response->successful()) {
                Log::warning('Bitbucket request failed', ['account_id' => $account->id, 'path' => $path, 'status' => $response->status()]);

                break;
            }

            $items = $items->merge($response->json('values') ?? []);
            $next = $response->json('next');

            if (! is_string($next) || ! str_starts_with($next, self::API.'/') || in_array($next, $fetched, true)) {
                break;
            }

            $fetched[] = $next;

            $response = $this->api($account)->get(substr($next, strlen(self::API)));
        }

        return $items;
    }

    private function api(SourceControlAccount $account): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(15);
    }
}
