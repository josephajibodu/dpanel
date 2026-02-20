<?php

namespace App\Http\Controllers;

use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeployScriptController extends Controller
{
    public function show(Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load(['server', 'deployScript']);

        return Inertia::render('sites/deploy-script/show', [
            'site' => new SiteResource($site),
        ]);
    }

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
