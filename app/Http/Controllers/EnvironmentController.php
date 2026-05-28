<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Jobs\SyncEnvironmentJob;
use App\Models\Server;
use App\Models\Site;
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
        $this->authorize('view', $site);

        $site->load(['server']);

        $hasWorkers = $site->server->workers()->where('site_id', $site->id)->exists();
        $envContent = '';

        try {
            $connection = $sshService->connect($site->server);
            $envContent = $connection->download($site->rootPath().'/.env');
        } catch (\Throwable) {
            $envContent = '';
        }

        return Inertia::render('sites/environment/show', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'has_workers' => $hasWorkers,
            'env_content' => $envContent,
        ]);
    }

    public function update(Request $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'env_content' => ['required', 'string'],
            'clear_config_cache' => ['sometimes', 'boolean'],
            'restart_queue' => ['sometimes', 'boolean'],
        ]);

        $clearConfigCache = (bool) ($validated['clear_config_cache'] ?? false);
        $restartQueue = (bool) ($validated['restart_queue'] ?? false);
        $variables = $this->parseEnvContent($validated['env_content']);

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
