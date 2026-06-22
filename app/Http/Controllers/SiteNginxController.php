<?php

namespace App\Http\Controllers;

use App\Enums\NginxFileSection;
use App\Enums\NginxHistoryEvent;
use App\Http\Requests\RenameNginxFileRequest;
use App\Http\Requests\StoreNginxFileRequest;
use App\Http\Requests\UpdateNginxFileRequest;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteResource;
use App\Jobs\ApplySiteNginxFileJob;
use App\Jobs\DeleteSiteNginxFileJob;
use App\Jobs\SyncSiteNginxFilesJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteNginxFile;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SiteNginxController extends Controller
{
    public function index(Team $team, Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $domains = $site->domains()
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'hostname' => $d->hostname,
                'is_primary' => $d->is_primary,
            ])
            ->values()
            ->all();

        $files = $site->nginxFiles()
            ->orderBy('section')
            ->orderBy('path')
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'ulid' => $f->ulid,
                'site_domain_id' => $f->site_domain_id,
                'section' => $f->section->value,
                'path' => $f->path,
                'content' => $f->content,
                'sync_status' => $f->sync_status,
                'sync_error' => $f->sync_error,
            ])
            ->values()
            ->all();

        $history = $site->nginxHistory()
            ->with('user:id,name')
            ->limit(50)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'event' => $h->event->value,
                'path' => $h->path,
                'old_path' => $h->old_path,
                'user_name' => $h->user?->name,
                'created_at' => $h->created_at?->toIso8601String(),
                'has_content' => $h->old_content !== null || $h->new_content !== null,
            ])
            ->values()
            ->all();

        return Inertia::render('sites/nginx/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'nginxFiles' => $files,
            'nginxHistory' => $history,
            'domains' => $domains,
            'sections' => collect(NginxFileSection::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'description' => $s->description(),
                'read_only' => $s->isReadOnly(),
            ])->values()->all(),
        ]);
    }

    public function store(StoreNginxFileRequest $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $server->isReady()) {
            return redirect()->back()->with('error', 'Server must be active and connected.');
        }

        if ($request->validated('section') === NginxFileSection::Main->value) {
            return redirect()->back()->with('error', 'Main configuration files are auto-generated and cannot be created manually.');
        }

        $domain = $site->domains()->findOrFail($request->validated('domain_id'));

        $path = $request->resolvedPath();

        if ($site->nginxFiles()->where('site_domain_id', $domain->id)->where('path', $path)->exists()) {
            return redirect()->back()->withErrors(['name' => "A file at {$path} already exists."]);
        }

        $file = $site->nginxFiles()->create([
            'site_domain_id' => $domain->id,
            'section' => $request->validated('section'),
            'path' => $path,
            'content' => $request->validated('content'),
            'sync_status' => 'pending',
        ]);

        ApplySiteNginxFileJob::dispatch(
            $file,
            NginxHistoryEvent::Created,
            null,
            null,
            $request->user()?->id,
        );

        return redirect()->back()->with('success', 'Nginx file created and queued for deployment.');
    }

    public function update(UpdateNginxFileRequest $request, Team $team, Server $server, Site $site, SiteNginxFile $nginxFile): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $server->isReady()) {
            return redirect()->back()->with('error', 'Server must be active and connected.');
        }

        $oldContent = $nginxFile->content;

        $nginxFile->update([
            'content' => $request->validated('content'),
            'sync_status' => 'pending',
            'sync_error' => null,
        ]);

        ApplySiteNginxFileJob::dispatch(
            $nginxFile,
            NginxHistoryEvent::Updated,
            $oldContent,
            null,
            $request->user()?->id,
        );

        return redirect()->back()->with('success', 'Nginx file updated and queued for deployment.');
    }

    public function rename(RenameNginxFileRequest $request, Team $team, Server $server, Site $site, SiteNginxFile $nginxFile): RedirectResponse
    {
        $this->authorize('update', $site);

        if ($nginxFile->section === NginxFileSection::Main) {
            return redirect()->back()->with('error', 'Main configuration files cannot be renamed.');
        }

        if (! $server->isReady()) {
            return redirect()->back()->with('error', 'Server must be active and connected.');
        }

        $oldPath = $nginxFile->path;
        $dir = dirname($oldPath);
        $newPath = $dir.'/'.$request->validated('name');

        if ($newPath === $oldPath) {
            return redirect()->back();
        }

        if ($site->nginxFiles()->where('path', $newPath)->exists()) {
            return redirect()->back()->withErrors(['name' => "A file at {$newPath} already exists."]);
        }

        $nginxFile->update([
            'path' => $newPath,
            'sync_status' => 'pending',
            'sync_error' => null,
        ]);

        ApplySiteNginxFileJob::dispatch(
            $nginxFile,
            NginxHistoryEvent::Renamed,
            null,
            $oldPath,
            $request->user()?->id,
        );

        return redirect()->back()->with('success', 'Nginx file renamed.');
    }

    public function destroy(Request $request, Team $team, Server $server, Site $site, SiteNginxFile $nginxFile): RedirectResponse
    {
        $this->authorize('update', $site);

        if ($nginxFile->section === NginxFileSection::Main) {
            return redirect()->back()->with('error', 'Main configuration files are managed automatically and cannot be deleted.');
        }

        if (! $server->isReady()) {
            return redirect()->back()->with('error', 'Server must be active and connected.');
        }

        $absolutePath = $nginxFile->absolutePath();
        $path = $nginxFile->path;
        $content = $nginxFile->content;

        $nginxFile->delete();

        DeleteSiteNginxFileJob::dispatch(
            $site,
            $absolutePath,
            $path,
            $content,
            $request->user()?->id,
        );

        return redirect()->back()->with('success', 'Nginx file deleted.');
    }

    public function history(Request $request, Team $team, Server $server, Site $site, int $historyId): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $site);

        $entry = $site->nginxHistory()->findOrFail($historyId);

        return response()->json([
            'old_content' => $entry->old_content,
            'new_content' => $entry->new_content,
        ]);
    }

    public function restore(Request $request, Team $team, Server $server, Site $site, int $historyId): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $server->isReady()) {
            return redirect()->back()->with('error', 'Server must be active and connected.');
        }

        $entry = $site->nginxHistory()->findOrFail($historyId);

        if ($entry->new_content === null) {
            return redirect()->back()->with('error', 'Cannot restore from a delete event.');
        }

        $file = $site->nginxFiles()->where('path', $entry->path)->first();

        if ($file === null) {
            $section = NginxFileSection::from(explode('/', $entry->path)[0]);
            $file = $site->nginxFiles()->create([
                'section' => $section,
                'path' => $entry->path,
                'content' => $entry->new_content,
                'sync_status' => 'pending',
            ]);
        } else {
            $oldContent = $file->content;
            $file->update([
                'content' => $entry->new_content,
                'sync_status' => 'pending',
                'sync_error' => null,
            ]);
        }

        ApplySiteNginxFileJob::dispatch(
            $file,
            NginxHistoryEvent::Updated,
            $oldContent ?? null,
            null,
            $request->user()?->id,
        );

        return redirect()->back()->with('success', 'Nginx file restored.');
    }

    public function refresh(Request $request, Team $team, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $server->isReady()) {
            return redirect()->back()->with('error', 'Server must be active and connected.');
        }

        SyncSiteNginxFilesJob::dispatch($site);

        return redirect()->back()->with('success', 'Syncing nginx files from server…');
    }
}
