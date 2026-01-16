<?php

namespace App\Http\Controllers;

use App\Jobs\SyncEnvironmentJob;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'variables' => ['required', 'array'],
            'variables.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Z_][A-Z0-9_]*$/i'],
            'variables.*.value' => ['nullable', 'string', 'max:65535'],
        ], [
            'variables.*.key.regex' => 'Variable names should start with a letter or underscore and contain only letters, numbers, and underscores.',
        ]);

        // Get existing variables to detect deletions
        $existingKeys = $site->environmentVariables()->pluck('key')->all();
        $newKeys = collect($validated['variables'])->pluck('key')->all();

        // Delete removed variables
        $keysToDelete = array_diff($existingKeys, $newKeys);
        if (! empty($keysToDelete)) {
            $site->environmentVariables()->whereIn('key', $keysToDelete)->delete();
        }

        // Update or create variables
        foreach ($validated['variables'] as $variable) {
            $site->environmentVariables()->updateOrCreate(
                ['key' => $variable['key']],
                ['value' => $variable['value'] ?? '']
            );
        }

        // Dispatch job to sync environment to server
        SyncEnvironmentJob::dispatch($site);

        return redirect()
            ->back()
            ->with('success', 'Environment variables updated. Syncing to server...');
    }
}
