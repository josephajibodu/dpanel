<?php

namespace App\Actions\Sites;

use App\Enums\SiteCommandRunStatus;
use App\Events\SiteCommandRunStatusChanged;
use App\Exceptions\SshCommandException;
use App\Jobs\RunSiteCommandJob;
use App\Models\Site;
use App\Models\SiteCommandRun;
use App\Services\Ssh\SshService;
use RuntimeException;

class RunSiteCommand
{
    use \App\Actions\Database\EscapesShell;

    public function __construct(
        private SshService $sshService
    ) {}

    public function run(Site $site, string $command): SiteCommandRun
    {
        $run = $site->commandRuns()->create([
            'user_id' => auth()->id(),
            'command' => $command,
            'status' => SiteCommandRunStatus::Pending,
        ]);
        SiteCommandRunStatusChanged::dispatch($run->fresh('site'));

        RunSiteCommandJob::dispatch($run)->onQueue('provisioning');

        return $run;
    }

    public function execute(SiteCommandRun $run): void
    {
        $site = $run->site;
        $server = $site->server;

        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $run->update([
            'status' => SiteCommandRunStatus::Running,
            'started_at' => now(),
        ]);
        SiteCommandRunStatusChanged::dispatch($run->fresh('site'));

        $connection = $this->sshService->connect($server);

        try {
            $rootPath = $site->rootPath();
            $escapedCommand = $this->escapeForShell($run->command);
            $fullCommand = "cd {$rootPath} && bash -lc {$escapedCommand} 2>&1";

            try {
                $output = $connection->exec($fullCommand, 300);
                $exitCode = 0;
            } catch (SshCommandException $e) {
                $output = $e->output.($e->stderr ? "\n".$e->stderr : '');
                $exitCode = $e->exitCode;
            }

            $run->update([
                'output' => $output,
                'status' => $exitCode === 0 ? SiteCommandRunStatus::Completed : SiteCommandRunStatus::Failed,
                'exit_code' => $exitCode,
                'finished_at' => now(),
            ]);
            SiteCommandRunStatusChanged::dispatch($run->fresh('site'));
        } finally {
            $connection->disconnect();
        }
    }
}
