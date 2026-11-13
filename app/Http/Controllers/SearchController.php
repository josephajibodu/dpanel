<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    /**
     * Everything in the team the search box can jump to. The whole list is
     * small, so it's sent at once and filtered in the browser as the user
     * types, which keeps results instant.
     *
     * Each server carries the same flags its sidebar uses to decide which of
     * its pages exist, so search never offers a page the server doesn't have.
     */
    public function index(Team $team): JsonResponse
    {
        $servers = $team->servers()
            ->with('installedServices')
            ->orderBy('name')
            ->get();

        $teamServerIds = $team->servers()->select('id');

        $sites = Site::query()
            ->whereIn('server_id', $teamServerIds)
            ->orderBy('domain')
            ->get(['id', 'server_id', 'domain', 'aliases', 'repository']);

        $databases = ServerDatabase::query()
            ->whereIn('server_id', $teamServerIds)
            ->orderBy('name')
            ->get(['id', 'server_id', 'name']);

        return response()->json([
            'servers' => $servers->map(fn (Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'ip_address' => $server->ip_address,
                'provider' => $server->provider->label(),
                'has_php' => $server->hasPhp(),
                'supports_sites' => $server->supportsSites(),
                'database_type' => $server->database_type,
                'accepts_connections' => $server->acceptsConnections(),
            ]),
            'sites' => $sites->map(fn (Site $site): array => [
                'id' => $site->id,
                'server_id' => $site->server_id,
                'domain' => $site->domain,
                'aliases' => $site->aliases ?? [],
                'repository' => $site->repository,
            ]),
            'databases' => $databases->map(fn (ServerDatabase $database): array => [
                'id' => $database->id,
                'server_id' => $database->server_id,
                'name' => $database->name,
            ]),
        ]);
    }
}
