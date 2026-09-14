<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Enums\SiteDomainType;
use App\Http\Resources\ServerResource;
use App\Http\Resources\SiteDomainResource;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Team;
use Carbon\CarbonInterface;
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

        return Inertia::render('dashboard', [
            'stats' => [
                'servers' => $team->servers()->count(),
                'active_servers' => $team->servers()->where('status', ServerStatus::Active)->count(),
                'sites' => Site::query()->whereHas('server', fn ($query) => $query->where('team_id', $team->id))->count(),
                'deployments_this_week' => Deployment::query()
                    ->whereHas('site.server', fn ($query) => $query->where('team_id', $team->id))
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'ssl_alerts' => $this->sslAlertsCount($team),
            ],
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

    private function sslAlertsCount(Team $team): int
    {
        $needsAttention = fn (?CarbonInterface $expiresAt) => in_array(
            SiteDomainResource::sslStatusFor($expiresAt),
            ['expiring_soon', 'expired'],
            true,
        );

        $customAlerts = SiteDomain::query()
            ->whereHas('site.server', fn ($query) => $query->where('team_id', $team->id))
            ->where('type', SiteDomainType::Custom)
            ->get(['ssl_expires_at'])
            ->filter(fn (SiteDomain $domain) => $needsAttention($domain->ssl_expires_at))
            ->count();

        $hasSystemDomains = SiteDomain::query()
            ->whereHas('site.server', fn ($query) => $query->where('team_id', $team->id))
            ->where('type', SiteDomainType::System)
            ->exists();

        if (! $hasSystemDomains) {
            return $customAlerts;
        }

        $wildcard = Certificate::firstWhere('domain', '*.'.config('server.free_domain'));

        return $needsAttention($wildcard?->expires_at) ? $customAlerts + 1 : $customAlerts;
    }
}
