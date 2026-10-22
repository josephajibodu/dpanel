<?php

namespace App\Http\Middleware;

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
            ],
            'currentTeam' => $user?->currentTeam,
            'servers' => $user?->currentTeam
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
