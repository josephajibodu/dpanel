<?php

use App\Models\Deployment;
use App\Models\Server;
use App\Realtime\RealtimeDiagnosticsChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Server channels - team members can subscribe to their team's server events
Broadcast::channel('servers.{teamId}', function ($user, $teamId) {
    return $user->teams()->where('team_id', $teamId)->exists()
        || $user->ownedTeams()->where('id', $teamId)->exists();
});

// Individual server channel - team members can subscribe to a specific server
Broadcast::channel('server.{serverId}', function ($user, $serverId) {
    $server = Server::find($serverId);

    return $server && $user->belongsToTeam($server->team);
});

// Deployment channel - team members can subscribe to deployment logs
Broadcast::channel('deployments.{deploymentId}', function ($user, $deploymentId) {
    $deployment = Deployment::find($deploymentId);

    $authorized = $deployment && $user->belongsToTeam($deployment->site->server->team);

    Log::debug('Broadcast channel authorization', [
        'channel' => 'deployments',
        'deployment_id' => (int) $deploymentId,
        'user_id' => $user->id,
        'authorized' => (bool) $authorized,
    ]);

    return $authorized;
});

Broadcast::channel('realtime.diagnostics.{userId}', function ($user, $userId) {
    $authorized = app(RealtimeDiagnosticsChannelAuthorizer::class)
        ->canAccess($user, (int) $userId);

    Log::debug('Broadcast channel authorization', [
        'channel' => 'realtime.diagnostics',
        'requested_user_id' => (int) $userId,
        'user_id' => $user->id,
        'authorized' => $authorized,
    ]);

    return $authorized;
});
