<?php

namespace App\Actions\Sites;

use App\Jobs\DeleteSiteJob;
use App\Models\Site;

class DeleteSiteAction
{
    public function execute(Site $site): void
    {
        // Dispatch job to remove site from server
        DeleteSiteJob::dispatch($site);
    }
}
