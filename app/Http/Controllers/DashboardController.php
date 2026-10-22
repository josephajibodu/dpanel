<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Enums\SiteDomainType;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteDomainResource;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Team $team): Response
    {
        $recentServers = $team->servers()
            ->withCount('sites')
            ->latest()
            ->take(5)
            ->get();

        $recentDeployments = Deployment::query()
            ->with('site')
            ->whereHas('site.server', fn ($query) => $query->where('team_id', $team->id))
            ->latest()
            ->take(5)
            ->get();

        $sslAlerts = $this->sslAlerts($team);

        return Inertia::render('dashboard', [
            'stats' => [
                'servers' => $team->servers()->count(),
                'active_servers' => $team->servers()->where('status', ServerStatus::Active)->count(),
                'sites' => Site::query()->whereHas('server', fn ($query) => $query->where('team_id', $team->id))->count(),
                'deployments_this_week' => Deployment::query()
                    ->whereHas('site.server', fn ($query) => $query->where('team_id', $team->id))
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'ssl_alerts' => count($sslAlerts),
            ],
            'sslAlerts' => $sslAlerts,
            'recentServers' => ServerResource::collection($recentServers),
            'recentDeployments' => $recentDeployments->map(fn (Deployment $deployment) => [
                'id' => $deployment->id,
                'ulid' => $deployment->ulid,
                'status' => $deployment->status->value,
                'status_label' => $deployment->status->label(),
                'status_color' => $deployment->status->color(),
                'commit_message' => $deployment->commit_message,
                'commit_hash_short' => $deployment->commit_hash ? substr($deployment->commit_hash, 0, 7) : null,
                'site' => [
                    'id' => $deployment->site->id,
                    'ulid' => $deployment->site->ulid,
                    'domain' => $deployment->site->domain,
                    'server_id' => $deployment->site->server_id,
                ],
                'created_at' => $deployment->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Custom-domain certificates on the team's sites that are expired or inside the renewal window,
     * so the dashboard can say which domain needs attention instead of just how many. The shared
     * free-domain wildcard is left out: FlitOps renews it, and CheckCertificateExpiryJob alerts the operator.
     *
     * @return list<array{hostname: string, ssl_status: string, ssl_expires_at: ?string, site: array{id: int, domain: string, server_id: int}}>
     */
    private function sslAlerts(Team $team): array
    {
        return SiteDomain::query()
            ->with('site:id,domain,server_id')
            ->whereHas('site.server', fn ($query) => $query->where('team_id', $team->id))
            ->where('type', SiteDomainType::Custom)
            ->get(['id', 'site_id', 'hostname', 'ssl_expires_at'])
            ->filter(fn (SiteDomain $domain) => in_array(
                SiteDomainResource::sslStatusFor($domain->ssl_expires_at),
                ['expiring_soon', 'expired'],
                true,
            ))
            ->sortBy('ssl_expires_at')
            ->map(fn (SiteDomain $domain) => [
                'hostname' => $domain->hostname,
                'ssl_status' => SiteDomainResource::sslStatusFor($domain->ssl_expires_at),
                'ssl_expires_at' => $domain->ssl_expires_at?->toIso8601String(),
                'site' => [
                    'id' => $domain->site->id,
                    'domain' => $domain->site->domain,
                    'server_id' => $domain->site->server_id,
                ],
            ])
            ->values()
            ->all();
    }
}
