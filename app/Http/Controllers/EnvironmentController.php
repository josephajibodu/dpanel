<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Jobs\SyncEnvironmentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentHistory;
use App\Models\Team;
use App\Services\Ssh\SshService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentController extends Controller
{
    public function show(Team $team, Server $server, Site $site, SshService $sshService): Response
    {
        $this->authorize('viewSecrets', $site);

        $site->load(['server']);

        $hasWorkers = $site->server->workers()->where('site_id', $site->id)->exists();

        return Inertia::render('sites/environment/show', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'has_workers' => $hasWorkers,
            'history' => $site->environmentHistory()
                ->with('user:id,name')
                ->limit(50)
                ->get()
                ->map(fn (SiteEnvironmentHistory $entry): array => [
                    'id' => $entry->id,
                    'user_name' => $entry->user?->name,
                    'added_keys' => $entry->added_keys,
                    'changed_keys' => $entry->changed_keys,
                    'removed_keys' => $entry->removed_keys,
                    'is_first_save' => $entry->old_content === null,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'env_content' => Inertia::defer(function () use ($site, $sshService): string {
                if ($site->env_content !== null) {
                    return $site->env_content;
                }

                try {
                    $connection = $sshService->connect($site->server);
                    $envPath = $site->sharedPath().'/.env';
                    $content = $site->isIsolated()
                        ? $connection->exec($site->commandAsSystemUser('cat '.escapeshellarg($envPath)))
                        : $connection->download($envPath);
                    $connection->disconnect();

                    return $content;
                } catch (\Throwable) {
                    return '';
                }
            }),
        ]);
    }

    public function update(Request $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('deploy', $site);

        $validated = $request->validate([
            'env_content' => ['required', 'string'],
            'clear_config_cache' => ['sometimes', 'boolean'],
            'restart_queue' => ['sometimes', 'boolean'],
        ]);

        $clearConfigCache = (bool) ($validated['clear_config_cache'] ?? false);
        $restartQueue = (bool) ($validated['restart_queue'] ?? false);
        $variables = $this->parseEnvContent($validated['env_content']);

        $this->recordHistory($site, $validated['env_content'], $variables, $request->user()?->id);

        $site->update(['env_content' => $validated['env_content']]);

        // Get existing variables to detect deletions
        $existingKeys = $site->environmentVariables()->pluck('key')->all();
        $newKeys = array_keys($variables);

        // Delete removed variables
        $keysToDelete = array_diff($existingKeys, $newKeys);
        if (! empty($keysToDelete)) {
            $site->environmentVariables()->whereIn('key', $keysToDelete)->delete();
        }

        // Update or create variables
        foreach ($variables as $key => $value) {
            $site->environmentVariables()->updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }

        // Dispatch job to sync environment to server
        SyncEnvironmentJob::dispatch($site, $clearConfigCache, $restartQueue);

        return redirect()
            ->back()
            ->with('success', 'Environment variables updated. Syncing to server...');
    }

    /**
     * Record who saved the environment and which keys the save added,
     * changed or removed. Saving the same content again records nothing.
     * When FlitOps has never stored this site's environment (it was only
     * read from the server), the earlier file is unknown, so the entry is
     * marked as a first save with no key breakdown.
     *
     * @param  array<string, string>  $newVariables
     */
    protected function recordHistory(Site $site, string $newContent, array $newVariables, ?int $userId): void
    {
        $oldContent = $site->env_content;

        if ($oldContent === null) {
            $storedVariables = $site->environmentVariables()->get();

            if ($storedVariables->isNotEmpty()) {
                $oldContent = $storedVariables->map(fn ($variable) => "{$variable->key}={$variable->value}")->implode("\n");
            }
        }

        if ($oldContent === $newContent) {
            return;
        }

        $oldVariables = [];

        if ($oldContent !== null) {
            try {
                $oldVariables = $this->parseEnvContent($oldContent);
            } catch (ValidationException) {
                $oldVariables = [];
            }
        }

        $isFirstSave = $oldContent === null;

        $changedKeys = array_keys(array_filter(
            array_intersect_key($newVariables, $oldVariables),
            fn (string $value, string $key): bool => $oldVariables[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $site->environmentHistory()->create([
            'user_id' => $userId,
            'added_keys' => $isFirstSave ? [] : array_values(array_keys(array_diff_key($newVariables, $oldVariables))),
            'changed_keys' => array_values($changedKeys),
            'removed_keys' => $isFirstSave ? [] : array_values(array_keys(array_diff_key($oldVariables, $newVariables))),
            'old_content' => $oldContent,
            'new_content' => $newContent,
        ]);
    }

    /**
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    protected function parseEnvContent(string $envContent): array
    {
        $variables = [];
        $lines = preg_split('/\r\n|\r|\n/', $envContent) ?: [];

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            $delimiterIndex = strpos($line, '=');
            if ($delimiterIndex === false) {
                throw ValidationException::withMessages([
                    'env_content' => 'Line '.($index + 1).' is invalid. Use KEY=value format.',
                ]);
            }

            $key = trim(substr($line, 0, $delimiterIndex));
            $value = ltrim(substr($line, $delimiterIndex + 1));

            if ($key === '' || ! preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                throw ValidationException::withMessages([
                    'env_content' => 'Line '.($index + 1).' has an invalid key. Keys must start with a letter or underscore and contain only letters, numbers, and underscores.',
                ]);
            }

            if (strlen($value) > 65535) {
                throw ValidationException::withMessages([
                    'env_content' => 'Line '.($index + 1).' value is too long.',
                ]);
            }

            $variables[$key] = $value;
        }

        return $variables;
    }
}
