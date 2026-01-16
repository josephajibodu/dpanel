<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeployScriptController extends Controller
{
    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'script' => ['required', 'string', 'max:65535'],
        ], [
            'script.required' => 'Deploy script cannot be empty.',
            'script.max' => 'Deploy script is too long.',
        ]);

        $site->deployScript()->updateOrCreate(
            ['site_id' => $site->id],
            ['script' => $validated['script']]
        );

        return redirect()
            ->back()
            ->with('success', 'Deploy script updated successfully.');
    }
}
