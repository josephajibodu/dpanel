<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Jobs\DeleteSiteJob;
use App\Models\Site;

class DeleteSiteAction
{
    public function execute(Site $site): void
    {
        $site->status = SiteStatus::Deleting;
        $site->save();

        $server = $site->server;
        if ($server) {
            event(new ServerSitesUpdated($server));
        }

        DeleteSiteJob::dispatch($site);
    }
}
