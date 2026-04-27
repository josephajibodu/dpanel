<?php

namespace App\Http\Controllers;

use App\Actions\Sites\SetPrimarySiteDomainAction;
use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use App\Http\Requests\StoreSiteDomainRequest;
use App\Http\Requests\UpdateSiteDomainRequest;
use App\Http\Requests\ValidateSiteDomainRequest;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteDomainResource;
use App\Http\Resources\SiteResource;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteDomainController extends Controller
{
    public function index(Server $server, Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load(['server', 'domains']);

        return Inertia::render('sites/domains/index', [
            'server' => new ServerResource($server),
            'site' => new SiteResource($site),
            'domains' => SiteDomainResource::collection($site->domains),
            'freeDomain' => config('server.free_domain'),
        ]);
    }

    public function validateHostname(ValidateSiteDomainRequest $request, Server $server, Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        $hostname = strtolower($request->validated('hostname'));
        $existing = SiteDomain::query()->where('hostname', $hostname)->with('site')->first();

        if ($existing) {
            $message = $existing->site_id === $site->id
                ? 'That domain is already added to this site.'
                : ($existing->site->server_id === $server->id
                    ? 'That domain has already been added to a site on this server.'
                    : 'That domain is already in use by another site.');

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['valid' => true]);
    }

    public function store(StoreSiteDomainRequest $request, Server $server, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validated();
        $hostname = strtolower($data['hostname']);

        if (SiteDomain::query()->where('hostname', $hostname)->exists()) {
            return redirect()
                ->back()
                ->withErrors(['hostname' => 'That domain is already in use.']);
        }

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => $hostname,
            'type' => SiteDomainType::Custom,
            'is_primary' => false,
            'wildcard_enabled' => $data['wildcard_enabled'],
            'www_redirect' => WwwRedirect::from($data['www_redirect']),
            'is_enabled' => true,
            'cloudflare_dns_record_id' => null,
        ]);

        SyncSiteNginxJob::dispatch($site);

        return redirect()
            ->route('servers.sites.domains.index', [$server, $site])
            ->with('success', 'Domain added.');
    }

    public function update(UpdateSiteDomainRequest $request, Server $server, Site $site, SiteDomain $siteDomain): RedirectResponse
    {
        $this->authorize('update', $site);

        $data = $request->validated();
        if (isset($data['www_redirect'])) {
            $data['www_redirect'] = WwwRedirect::from($data['www_redirect']);
        }

        if (array_key_exists('is_enabled', $data) && $data['is_enabled'] === false) {
            if ($siteDomain->is_primary) {
                return redirect()
                    ->route('servers.sites.domains.index', [$server, $site])
                    ->with('error', 'Set another domain as primary before disabling this domain.');
            }

            $enabledCount = $site->domains()->where('is_enabled', true)->count();
            if ($enabledCount <= 1) {
                return redirect()
                    ->route('servers.sites.domains.index', [$server, $site])
                    ->with('error', 'At least one domain must remain enabled.');
            }
        }

        $siteDomain->update($data);

        SyncSiteNginxJob::dispatch($site);

        return redirect()
            ->route('servers.sites.domains.index', [$server, $site])
            ->with('success', 'Domain updated.');
    }

    public function destroy(Server $server, Site $site, SiteDomain $siteDomain): RedirectResponse
    {
        $this->authorize('update', $site);

        if ($siteDomain->type === SiteDomainType::System) {
            return redirect()
                ->route('servers.sites.domains.index', [$server, $site])
                ->with('error', 'The system domain cannot be deleted.');
        }

        $count = $site->domains()->count();
        if ($count <= 1) {
            return redirect()
                ->route('servers.sites.domains.index', [$server, $site])
                ->with('error', 'You must keep at least one domain.');
        }

        if ($siteDomain->is_primary) {
            return redirect()
                ->route('servers.sites.domains.index', [$server, $site])
                ->with('error', 'Set another domain as primary before deleting this one.');
        }

        $siteDomain->delete();

        SyncSiteNginxJob::dispatch($site);

        return redirect()
            ->route('servers.sites.domains.index', [$server, $site])
            ->with('success', 'Domain removed.');
    }

    public function setPrimary(Server $server, Site $site, SiteDomain $siteDomain, SetPrimarySiteDomainAction $action): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $siteDomain->is_enabled) {
            return redirect()
                ->route('servers.sites.domains.index', [$server, $site])
                ->with('error', 'Enable the domain before setting it as primary.');
        }

        $action->execute($site, $siteDomain);

        return redirect()
            ->route('servers.sites.domains.index', [$server, $site])
            ->with('success', 'Primary domain updated.');
    }
}
