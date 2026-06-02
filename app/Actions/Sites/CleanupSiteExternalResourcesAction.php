<?php

namespace App\Actions\Sites;

use App\Models\Server;
use App\Models\SourceControlAccount;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\SourceControlService;
use Illuminate\Support\Facades\Log;

/**
 * Tears down external (third-party) resources tied to a site: Cloudflare DNS
 * records and, when requested, the GitHub account-level SSH key added for the
 * site's server.
 *
 * Designed to be called both from DeleteSiteJob (per-site delete) and from
 * DeleteServerJob (looping over the server's sites before the VPS itself is
 * destroyed). The caller is responsible for any server-side cleanup over SSH —
 * this action only talks to remote APIs.
 */
class CleanupSiteExternalResourcesAction
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
        private SourceControlService $sourceControl,
    ) {}

    /**
     * @param  array<int, string>  $cloudflareDnsRecordIds  Pre-resolved record IDs. Accepted as an array (not a Site) so queued callers can serialize them safely even if the Site row is gone by the time the job runs.
     * @param  ?Server  $server  Server the site lives on. Required for GitHub SSH key cleanup.
     * @param  ?SourceControlAccount  $sourceControlAccount  Account whose SSH key would have been added for this server. Required for GitHub SSH key cleanup.
     * @param  ?string  $domain  Optional site domain — used only for log context.
     * @param  bool  $cleanupGithubSshKey  Set false when the caller (e.g. DeleteServerJob) is doing a server-wide GitHub key sweep separately, to avoid redundant API calls.
     */
    public function execute(
        array $cloudflareDnsRecordIds,
        ?Server $server = null,
        ?SourceControlAccount $sourceControlAccount = null,
        ?string $domain = null,
        bool $cleanupGithubSshKey = true,
    ): void {
        $logSuffix = $domain ? " for {$domain}" : '';

        foreach ($cloudflareDnsRecordIds as $recordId) {
            try {
                $this->cloudflare->deleteRecord($recordId);
                Log::info("Deleted Cloudflare DNS record {$recordId}{$logSuffix}");
            } catch (\Throwable $e) {
                Log::warning("Failed to delete Cloudflare DNS record {$recordId}{$logSuffix}: {$e->getMessage()}");
            }
        }

        if (! $cleanupGithubSshKey || ! $server || ! $sourceControlAccount) {
            return;
        }

        try {
            $this->sourceControl->deleteAccountSshKeyIfUnused($server, $sourceControlAccount);
            Log::info("SSH key cleanup attempted{$logSuffix}");
        } catch (\Throwable $e) {
            Log::warning("Failed to delete SSH key{$logSuffix}: {$e->getMessage()}");
        }
    }
}
