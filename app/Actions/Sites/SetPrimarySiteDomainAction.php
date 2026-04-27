<?php

namespace App\Actions\Sites;

use App\Jobs\SyncSiteNginxJob;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Support\Facades\DB;

class SetPrimarySiteDomainAction
{
    public function execute(Site $site, SiteDomain $domain): void
    {
        DB::transaction(function () use ($site, $domain): void {
            $site->domains()->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);
            $site->update(['domain' => $domain->hostname]);
        });

        SyncSiteNginxJob::dispatch($site);
    }
}
