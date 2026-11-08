<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Services\SourceControlService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Points a site at another repository (or at none). The next deployment
 * clones the new one, since every deployment clones into a fresh release.
 *
 * Deploy on push is switched off first, so the old repository's webhook is
 * removed; the caller turns it back on for the new repository if wanted. The
 * server's key is added to the new account so the server can clone, and
 * taken off the old account once no site on the server uses it.
 */
class ChangeSiteRepositoryAction
{
    public function __construct(
        private SourceControlService $sourceControl,
        private SetDeployOnPushAction $setDeployOnPush,
    ) {}

    /**
     * @throws RuntimeException when the old repository's webhook can't be removed, with a message fit to show the user.
     */
    public function execute(Site $site, ?SourceControlAccount $account, ?string $repository): void
    {
        if ($account === null || $repository === null) {
            $account = null;
            $repository = null;
        }

        if ($site->source_control_account_id === $account?->id && $site->repository === $repository) {
            return;
        }

        $this->setDeployOnPush->execute($site, false);

        $previousAccount = $site->sourceControlAccount;

        $site->update([
            'source_control_account_id' => $account?->id,
            'repository' => $repository,
            'repository_provider' => $account?->provider,
        ]);
        $site->unsetRelation('sourceControlAccount');

        if ($account !== null) {
            $this->sourceControl->ensureAccountSshKey($site->server, $account);
        }

        if ($previousAccount !== null && $previousAccount->id !== $account?->id) {
            try {
                $this->sourceControl->deleteAccountSshKeyIfUnused($site->server, $previousAccount);
            } catch (\Throwable $e) {
                Log::warning("Failed to remove the server key from the previous source control account of site {$site->id}: {$e->getMessage()}");
            }
        }
    }
}
