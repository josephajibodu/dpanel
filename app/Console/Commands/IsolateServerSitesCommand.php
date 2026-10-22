<?php

namespace App\Console\Commands;

use App\Actions\Sites\IsolateSiteAction;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

class IsolateServerSitesCommand extends Command
{
    protected $signature = 'flitops:server:isolate-sites
        {server : The ID of the server whose sites should each get their own Linux user}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Move every site on a server that still runs as the server user to its own Linux user.';

    public function handle(): int
    {
        $server = Server::query()->find($this->argument('server'));

        if (! $server) {
            $this->components->error('No server found with that ID.');

            return self::FAILURE;
        }

        $sites = $server->sites()->whereNull('system_user')->orderBy('id')->get();

        if ($sites->isEmpty()) {
            $this->components->info("Every site on {$server->name} is already isolated.");

            return self::SUCCESS;
        }

        $this->components->warn('Each site below will be unavailable for a few seconds while its files move. A site that fails to move is put back as it was.');

        foreach ($sites as $site) {
            $this->components->twoColumnDetail($site->domain, $site->rootPath());
        }

        if (! $this->option('force') && ! $this->components->confirm("Isolate these {$sites->count()} sites?")) {
            return self::FAILURE;
        }

        $failed = 0;

        $sites->each(function (Site $site) use (&$failed): void {
            try {
                app(IsolateSiteAction::class)->trigger($site);
                $this->components->twoColumnDetail($site->domain, '<fg=green>queued</>');
            } catch (\RuntimeException $e) {
                $failed++;
                $this->components->twoColumnDetail($site->domain, "<fg=red>skipped</> {$e->getMessage()}");
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
