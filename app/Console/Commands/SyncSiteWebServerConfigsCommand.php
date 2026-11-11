<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

class SyncSiteWebServerConfigsCommand extends Command
{
    protected $signature = 'flitops:web-server:sync-sites
        {--server=* : Specific server ID(s) to target}
        {--all : Apply to every active server}';

    protected $description = 'Rebuild every site\'s Nginx or Caddy config from its current settings, e.g. after the generated config changes.';

    public function handle(): int
    {
        $serverIds = $this->option('server');

        if (empty($serverIds) && ! $this->option('all')) {
            $this->components->error('Pass --server=ID (repeatable) or --all.');

            return self::FAILURE;
        }

        $servers = $this->option('all')
            ? Server::query()->where('status', ServerStatus::Active)->whereNotNull('web_server')->get()
            : Server::query()->whereIn('id', $serverIds)->whereNotNull('web_server')->get();

        $sites = Site::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->whereNotIn('status', [SiteStatus::Pending, SiteStatus::Installing, SiteStatus::Isolating, SiteStatus::Deleting])
            ->with('server')
            ->get();

        if ($sites->isEmpty()) {
            $this->components->warn('No matching sites found.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($sites as $site) {
            try {
                $this->components->task("{$site->domain} (server {$site->server_id})", function () use ($site) {
                    $site->server->webServer()->syncSite($site);

                    return true;
                });
            } catch (\Throwable $e) {
                $failures++;
                $this->components->error("{$site->domain}: {$site->server->redactSecrets($e->getMessage())}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
