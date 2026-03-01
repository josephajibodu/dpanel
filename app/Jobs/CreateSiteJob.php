<?php

namespace App\Jobs;

use App\Actions\Sites\ProvisionSiteAction;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 0;

    public int $timeout = 300;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(ProvisionSiteAction $action): void
    {
        $action->execute($this->site);
    }
}
