<?php

namespace App\Actions\SourceControl;

use App\Models\SourceControlAccount;
use App\Services\SourceControlService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SyncRepositoriesAction
{
    public function __construct(private SourceControlService $sourceControlService) {}

    /**
     * Fetch the latest repository list from the provider, upsert the cache, and
     * prune any rows the account no longer has access to. Returns the cached
     * rows after the sync, ordered by full_name.
     *
     * If the provider returns an empty collection (typically a token failure
     * silently logged by the service), the existing cache is left untouched so
     * a transient API error does not wipe a previously good list.
     */
    public function execute(SourceControlAccount $account): Collection
    {
        $providerRepos = $this->sourceControlService->listRepositories($account);

        if ($providerRepos->isEmpty()) {
            return $account->repositories()->orderBy('full_name')->get();
        }

        DB::transaction(function () use ($account, $providerRepos): void {
            $providerIds = [];

            foreach ($providerRepos as $repo) {
                $providerRepoId = (string) $repo['id'];
                $providerIds[] = $providerRepoId;

                $account->repositories()->updateOrCreate(
                    ['provider_repo_id' => $providerRepoId],
                    [
                        'name' => $repo['name'],
                        'full_name' => $repo['full_name'],
                        'ssh_url' => $repo['ssh_url'],
                        'html_url' => $repo['html_url'],
                        'default_branch' => $repo['default_branch'],
                        'private' => $repo['private'],
                    ],
                );
            }

            $account->repositories()
                ->whereNotIn('provider_repo_id', $providerIds)
                ->delete();

            $account->forceFill(['repositories_synced_at' => now()])->save();
        });

        return $account->repositories()->orderBy('full_name')->get();
    }
}
