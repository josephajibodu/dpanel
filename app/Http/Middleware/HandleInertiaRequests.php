<?php

namespace App\Http\Middleware;

use App\Enums\TeamPermission;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Team-dependent props are closures so they are resolved after the
     * `team.slug` middleware has switched the user to the team in the URL.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'permissions' => fn () => $user ? $this->permissionsFor($user) : [],
            ],
            'currentTeam' => fn () => $user?->currentTeam,
            'servers' => fn () => $user?->currentTeam
                ? $user->currentTeam->servers()->select(['id', 'name'])->orderBy('name')->get()
                : [],
            'sites' => fn () => $this->resolveSitesForRequest($request),
            'teams' => $user ? $user->teams()->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'personal_team' => $t->personal_team,
                'slug' => $t->slug,
            ]) : [],
            'alerts' => fn () => $user ? $this->alertsFor($user) : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'deployment_started' => $request->session()->get('deployment_started'),
            ],
        ];
    }

    /**
     * What the user may do in their current team, keyed by permission value.
     * Only used to hide controls the user can't use; every action is still
     * authorized on the server.
     *
     * @return array<string, bool>
     */
    protected function permissionsFor(User $user): array
    {
        $team = $user->currentTeam;

        return collect(TeamPermission::cases())
            ->mapWithKeys(fn (TeamPermission $permission): array => [
                $permission->value => $team !== null && $user->hasTeamPermission($team, $permission),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, domain: string}>
     */
    protected function resolveSitesForRequest(Request $request): array
    {
        $server = $request->route('server');

        if (! $server instanceof Server) {
            return [];
        }

        return $server->sites()
            ->select(['id', 'domain'])
            ->orderBy('domain')
            ->get()
            ->all();
    }

    /**
     * Unread failure alerts, shown in a banner on every page until dismissed.
     *
     * @return list<array{id: string, title: string, message: ?string, url: ?string, created_at: string}>
     */
    protected function alertsFor(User $user): array
    {
        return $user->unreadNotifications()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'Something needs your attention',
                'message' => $notification->data['message'] ?? null,
                'url' => $notification->data['url'] ?? null,
                'created_at' => $notification->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
