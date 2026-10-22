<?php

namespace App\Actions\Sites;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\KeepsSecretsOffCommandLine;
use App\Enums\DeploymentStatus;
use App\Enums\NginxFileSection;
use App\Enums\ProjectType;
use App\Enums\SiteDomainType;
use App\Enums\SiteStatus;
use App\Jobs\IsolateSiteJob;
use App\Models\CronJob;
use App\Models\Site;
use App\Models\Worker;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\SiteProvisioning\SiteIsolationService;
use App\Services\SiteUserNameGenerator;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Converts an existing site that runs as the server user into an isolated
 * site with its own Linux user, the same as a site created isolated.
 *
 * Every step is undone, newest first, if a later one fails, so the site ends
 * up either fully isolated or exactly as it was. Nothing is saved to the
 * database until the server side has fully switched over.
 *
 * One instance handles one conversion: the run's state (paths, completed
 * steps, original values) lives in properties while it runs.
 */
class IsolateSiteAction
{
    use EscapesShell, KeepsSecretsOffCommandLine;

    private SshConnection $connection;

    private Site $site;

    private string $systemUser;

    private string $oldRoot;

    private string $newRoot;

    private string $oldWebRoot;

    /**
     * Private folder (readable by the server user only) for staging files.
     */
    private string $directory;

    /**
     * File holding the original owner and mode of every file, so a rollback
     * can put them back exactly.
     */
    private ?string $manifest = null;

    /**
     * Steps finished so far, in order.
     *
     * @var list<string>
     */
    private array $completed = [];

    /**
     * @var array<int, string> Original content of the site's main nginx files, by id.
     */
    private array $originalNginxContent = [];

    /**
     * @var array<int, string> Original users of the site's workers, by id.
     */
    private array $originalWorkerUsers = [];

    /**
     * @var array<int, string> Original users of the site's cron jobs, by id.
     */
    private array $originalCronUsers = [];

    /**
     * @var Collection<int, Worker>
     */
    private Collection $workers;

    /**
     * @var Collection<int, CronJob>
     */
    private Collection $cronJobs;

    public function __construct(
        private SshService $sshService,
        private SiteUserNameGenerator $names,
        private SiteIsolationService $isolation,
        private SiteNginxSyncService $nginx,
    ) {}

    /**
     * Check the site can be converted and queue the conversion.
     *
     * @throws RuntimeException with a message for the user when it can't.
     */
    public function trigger(Site $site): void
    {
        if ($site->isIsolated()) {
            throw new RuntimeException('This site is already isolated.');
        }

        if (! in_array($site->status, [SiteStatus::Provisioned, SiteStatus::Deployed], true)) {
            throw new RuntimeException('Only a site that is set up and not busy can be isolated. Wait for the current task to finish and try again.');
        }

        if (! $site->server->isReady()) {
            throw new RuntimeException("The server isn't connected right now, so the site can't be isolated.");
        }

        $deploymentInProgress = $site->deployments()
            ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
            ->exists();

        if ($deploymentInProgress) {
            throw new RuntimeException('A deployment is running. Try again once it finishes.');
        }

        $previousStatus = $site->status;
        $site->update(['status' => SiteStatus::Isolating, 'error_message' => null]);

        IsolateSiteJob::dispatch($site, $previousStatus);
    }

    /**
     * @throws Throwable when the conversion fails (after it has been rolled back).
     */
    public function execute(Site $site, SiteStatus $previousStatus): void
    {
        if ($site->isIsolated()) {
            $site->update(['status' => $previousStatus]);

            return;
        }

        $this->site = $site;
        $this->oldRoot = $site->rootPath();
        $this->oldWebRoot = $site->webRoot();
        $this->systemUser = $this->names->generate($site->server, $site->domain);
        $this->workers = Worker::query()->where('site_id', $site->id)->get();
        $this->cronJobs = CronJob::query()->where('site_id', $site->id)->get();

        $this->connection = $this->sshService->connect($site->server);

        try {
            $this->withPrivateDirectory($this->connection, function (string $directory): void {
                try {
                    $this->convert($directory);
                } catch (Throwable $e) {
                    Log::error("Isolating site {$this->site->domain} failed, rolling back: {$e->getMessage()}");
                    $this->rollBack();

                    throw $e;
                }
            });
        } catch (Throwable $e) {
            $this->site->refresh();
            $this->site->update([
                'status' => $previousStatus,
                'error_message' => 'Isolating this site failed, so it was put back as it was: '.$site->server->redactSecrets($e->getMessage()),
            ]);

            throw $e;
        } finally {
            $this->connection->disconnect();
        }

        $this->site->update(['status' => $previousStatus, 'error_message' => null]);
    }

    /**
     * Each step is recorded before it starts, so one that fails partway is
     * undone too (every undo is safe to run on a step that only half ran).
     */
    private function convert(string $directory): void
    {
        $this->directory = $directory;
        $this->site->system_user = $this->systemUser;
        $this->newRoot = $this->site->rootPath();

        $this->completed[] = 'user';
        $this->isolation->createUser($this->connection, $this->site);

        $this->completed[] = 'pool';
        $this->isolation->writePool($this->connection, $this->site, $this->phpVersion());

        $this->moveFiles();

        $this->completed[] = 'paths';
        $this->rewritePaths($this->oldRoot, $this->newRoot);
        $this->recacheLaravelConfig();

        $this->completed[] = 'nginx';
        $this->switchNginx();

        $this->completed[] = 'certificates';
        $this->connection->exec('sudo mkdir -p '.$this->escapeForShell(config('server.acme_challenge_path').'/.well-known/acme-challenge'));
        $this->pointCertificateRenewalsAt($this->oldWebRoot, config('server.acme_challenge_path'));

        $this->completed[] = 'processes';
        $this->moveProcesses();

        $this->saveConversion();
    }

    /**
     * Move the site's folder into the new user's home and hand it over. When
     * an earlier attempt already moved it (and then stopped without rolling
     * back), the move is skipped.
     */
    private function moveFiles(): void
    {
        $old = $this->escapeForShell($this->oldRoot);
        $new = $this->escapeForShell($this->newRoot);
        $user = $this->escapeForShell($this->systemUser);

        $where = trim($this->connection->exec('sudo bash -c '.$this->escapeForShell(
            "if [ -d {$old} ] && [ ! -e {$new} ]; then echo old; elif [ ! -e {$old} ] && [ -d {$new} ]; then echo new; else echo unknown; fi"
        )));

        if ($where === 'unknown') {
            throw new RuntimeException("Couldn't tell where the site's files are: expected exactly one of {$this->oldRoot} and {$this->newRoot}.");
        }

        if ($where === 'old') {
            $this->manifest = "{$this->directory}/ownership";
            $this->connection->exec("sudo find {$old} -printf '%U:%G %m %p\\0' > ".$this->escapeForShell($this->manifest), 600);
            $this->connection->exec("sudo mv -T {$old} {$new}");
        }

        $this->completed[] = 'files';

        $this->connection->exec("sudo chown -R {$user}:{$user} {$new}", 600);

        foreach ($this->secretFiles() as $relative) {
            $path = $this->escapeForShell("{$this->site->sharedPath()}/{$relative}");
            $this->connection->exec($this->site->commandAsSystemUser("if [ -f {$path} ]; then chmod 600 {$path}; fi"));
        }
    }

    /**
     * Point every symlink and env file that names the old location (the
     * `current` release, each release's shared links, the SQLite path in
     * .env) at the new one.
     */
    private function rewritePaths(string $from, string $to): void
    {
        $root = $this->escapeForShell($this->site->rootPath());
        $fromEsc = $this->escapeForShell($from);
        $toEsc = $this->escapeForShell($to);

        $relink = "find {$root} -type l -lname {$this->escapeForShell($from.'/*')} -print0 | "
            .'while IFS= read -r -d \'\' link; do target=$(readlink "$link"); '
            .'ln -sfn "$2${target#"$1"}" "$link"; done';

        $this->connection->exec($this->site->commandAsSystemUser("bash -c {$this->escapeForShell($relink)} _ {$fromEsc} {$toEsc}"), 600);

        $sed = 's#'.$this->sedPattern($from.'/').'#'.$this->sedReplacement($to.'/').'#g';

        foreach ($this->envFiles() as $relative) {
            $path = $this->escapeForShell("{$this->site->sharedPath()}/{$relative}");
            $this->connection->exec($this->site->commandAsSystemUser("if [ -f {$path} ]; then sed -i {$this->escapeForShell($sed)} {$path}; fi"));
        }
    }

    /**
     * Laravel's cached config holds absolute paths, so it's rebuilt from the
     * new location when the site had one.
     */
    private function recacheLaravelConfig(): void
    {
        if ($this->site->project_type !== ProjectType::Laravel) {
            return;
        }

        $current = $this->escapeForShell($this->site->currentPath());
        $php = $this->escapeForShell('php'.$this->phpVersion());

        $this->connection->exec($this->site->commandAsSystemUser(
            "if [ -f {$current}/bootstrap/cache/config.php ]; then cd {$current} && {$php} artisan config:cache; fi"
        ), 120);
    }

    /**
     * Repoint the site's stored main nginx configs at the new folder and
     * PHP-FPM socket (they're kept as written, user edits included), then
     * regenerate everything and reload nginx after `nginx -t` passes.
     */
    private function switchNginx(): void
    {
        $serverSocket = '#/run/php/php[0-9.]+-fpm-'.preg_quote((string) config('server.user'), '#').'\.sock#';

        $mainFiles = $this->site->nginxFiles()->where('section', NginxFileSection::Main->value)->get();

        foreach ($mainFiles as $file) {
            $this->originalNginxContent[$file->id] = (string) $file->content;

            $content = str_replace($this->oldRoot.'/', $this->newRoot.'/', (string) $file->content);
            $content = (string) preg_replace($serverSocket, $this->site->phpFpmSocket($this->phpVersion()), $content);

            $file->update(['content' => $content, 'hash' => md5($content)]);
        }

        $this->nginx->sync($this->site, $this->connection);
    }

    /**
     * Let's Encrypt renews each custom domain's certificate through the
     * folder recorded when it was issued, so that record moves with the site.
     */
    private function pointCertificateRenewalsAt(string $from, string $to): void
    {
        $sed = 's#= '.$this->sedPattern($from).'\(,\?\)$#= '.$this->sedReplacement($to).'\1#';

        foreach ($this->site->domains()->where('type', SiteDomainType::Custom)->pluck('hostname') as $hostname) {
            $renewal = $this->escapeForShell("/etc/letsencrypt/renewal/{$hostname}.conf");

            $this->connection->exec("if sudo test -f {$renewal}; then sudo sed -i {$this->escapeForShell($sed)} {$renewal}; fi");
        }
    }

    /**
     * Run the site's workers and cron jobs as the site's user from the new
     * folder. Stopped workers stay stopped.
     */
    private function moveProcesses(): void
    {
        foreach ($this->workers as $worker) {
            $this->originalWorkerUsers[$worker->id] = $worker->user;
            $worker->user = $this->systemUser;
            $worker->setRelation('site', $this->site);
        }

        foreach ($this->cronJobs as $cronJob) {
            $this->originalCronUsers[$cronJob->id] = $cronJob->user;
            $cronJob->user = $this->systemUser;
            $cronJob->setRelation('site', $this->site);
        }

        $this->writeProcessFiles();
    }

    private function writeProcessFiles(): void
    {
        $prefix = config('supervisor.file_prefix');
        $workersWithFiles = $this->workers->reject(fn (Worker $worker): bool => in_array($worker->status, ['pending', 'failed', 'deleting'], true));

        foreach ($workersWithFiles as $worker) {
            $this->installRootFile($worker->supervisorConfig("{$prefix}-{$worker->id}"), config('supervisor.conf_path')."/{$prefix}-{$worker->id}.conf");
        }

        foreach ($this->cronJobs->filter(fn (CronJob $cronJob): bool => $cronJob->status === 'active' && ! $cronJob->hidden) as $cronJob) {
            $this->installRootFile($cronJob->cronLine(), config('cron.cron_d_path').'/'.config('cron.file_prefix')."-{$cronJob->id}");
        }

        if ($workersWithFiles->isEmpty()) {
            return;
        }

        $this->connection->exec('sudo supervisorctl reread', 30);
        $this->connection->exec('sudo supervisorctl update', 60);

        foreach ($workersWithFiles->where('status', 'stopped') as $worker) {
            $this->connection->exec('sudo supervisorctl stop '.$this->escapeForShell("{$prefix}-{$worker->id}:*"), 30);
        }
    }

    private function installRootFile(string $content, string $destination): void
    {
        $staged = $this->directory.'/'.basename($destination);

        $this->connection->upload($content, $staged);
        $this->connection->exec('sudo install -o root -g root -m 644 '.$this->escapeForShell($staged).' '.$this->escapeForShell($destination));
    }

    private function saveConversion(): void
    {
        DB::transaction(function (): void {
            $this->site->save();

            $deployScript = $this->site->deployScript;

            if ($deployScript && str_contains((string) $deployScript->script, $this->oldRoot.'/')) {
                $deployScript->update(['script' => str_replace($this->oldRoot.'/', $this->newRoot.'/', $deployScript->script)]);
            }

            $this->workers->each->save();
            $this->cronJobs->each->save();
        });
    }

    /**
     * Undo the finished steps, newest first. Each undo is attempted even if
     * an earlier one fails, so as much as possible is put back.
     */
    private function rollBack(): void
    {
        foreach (array_reverse($this->completed) as $step) {
            try {
                match ($step) {
                    'processes' => $this->undoProcesses(),
                    'certificates' => $this->pointCertificateRenewalsAt(config('server.acme_challenge_path'), $this->oldWebRoot),
                    'nginx' => $this->undoNginx(),
                    'paths' => $this->rewritePaths($this->newRoot, $this->oldRoot),
                    'files' => $this->undoMove(),
                    'pool' => $this->isolation->removePool($this->connection, $this->systemUser, $this->phpVersion()),
                    'user' => $this->undoUser(),
                };
            } catch (Throwable $e) {
                Log::error("Rolling back step \"{$step}\" of isolating site {$this->site->domain} failed: {$e->getMessage()}");
            }
        }
    }

    private function undoProcesses(): void
    {
        $this->site->system_user = null;

        foreach ($this->workers as $worker) {
            $worker->user = $this->originalWorkerUsers[$worker->id] ?? $worker->user;
        }

        foreach ($this->cronJobs as $cronJob) {
            $cronJob->user = $this->originalCronUsers[$cronJob->id] ?? $cronJob->user;
        }

        try {
            $this->writeProcessFiles();
        } finally {
            $this->site->system_user = $this->systemUser;
        }
    }

    private function undoNginx(): void
    {
        foreach ($this->originalNginxContent as $id => $content) {
            $this->site->nginxFiles()->whereKey($id)->update(['content' => $content, 'hash' => md5($content)]);
        }

        $this->site->system_user = null;

        try {
            $this->nginx->sync($this->site, $this->connection);
        } finally {
            $this->site->system_user = $this->systemUser;
        }
    }

    /**
     * Move the folder back and restore every file's original owner and mode.
     * Without a record of them (an earlier attempt moved the files), the
     * server user gets the files back with the web user as group.
     */
    private function undoMove(): void
    {
        $old = $this->escapeForShell($this->oldRoot);
        $new = $this->escapeForShell($this->newRoot);

        $this->connection->exec("sudo mv -T {$new} {$old}");

        if ($this->manifest === null) {
            $owner = config('server.user').':'.config('server.web_user');
            $this->connection->exec('sudo chown -R '.$this->escapeForShell($owner)." {$old}", 600);

            return;
        }

        $restore = 'while IFS= read -r -d \'\' record; do '
            .'owner=${record%% *}; rest=${record#* }; mode=${rest%% *}; path=${rest#* }; '
            .'chown -h "$owner" "$path"; [ -L "$path" ] || chmod "$mode" "$path"; '
            .'done < "$1"';

        $this->connection->exec('sudo bash -c '.$this->escapeForShell($restore).' _ '.$this->escapeForShell($this->manifest), 600);

        if ($this->site->project_type === ProjectType::Laravel) {
            $current = $this->escapeForShell($this->oldRoot.'/current');
            $php = $this->escapeForShell('php'.$this->phpVersion());

            $this->connection->exec("if [ -f {$current}/bootstrap/cache/config.php ]; then cd {$current} && {$php} artisan config:cache; fi", 120);
        }
    }

    /**
     * Delete the user only once its home no longer holds the site's files,
     * since deleting the user deletes its home too.
     */
    private function undoUser(): void
    {
        $filesStillMoved = trim($this->connection->exec('sudo bash -c '.$this->escapeForShell(
            'if [ -e '.$this->escapeForShell($this->newRoot).' ]; then echo yes; fi'
        ))) === 'yes';

        if ($filesStillMoved) {
            throw new RuntimeException("The site's files are still in {$this->newRoot}, so user {$this->systemUser} was kept.");
        }

        $this->isolation->deleteUser($this->connection, $this->systemUser, $this->site->ulid);
    }

    private function phpVersion(): string
    {
        return $this->site->php_version ?? '8.4';
    }

    /**
     * Shared files that hold secrets, relative to shared/.
     *
     * @return list<string>
     */
    private function secretFiles(): array
    {
        return match ($this->site->project_type) {
            ProjectType::WordPress => ['wp-config.php'],
            ProjectType::Symfony => ['.env', '.env.local'],
            ProjectType::StaticHtml => [],
            default => ['.env'],
        };
    }

    /**
     * Shared env files that may name the site's folder, relative to shared/.
     *
     * @return list<string>
     */
    private function envFiles(): array
    {
        return array_values(array_filter($this->secretFiles(), fn (string $file): bool => str_starts_with($file, '.env')));
    }

    /**
     * Escape a literal string for a sed pattern that uses "#" as its delimiter.
     */
    private function sedPattern(string $value): string
    {
        return addcslashes($value, '\\#.[]*^$');
    }

    /**
     * Escape a literal string for a sed replacement that uses "#" as its delimiter.
     */
    private function sedReplacement(string $value): string
    {
        return addcslashes($value, '\\#&');
    }
}
