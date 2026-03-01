<?php

use App\Models\Deployment;
use App\Models\Server;
use App\Realtime\RealtimeDiagnosticsChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Server channels - user can subscribe to all their servers
Broadcast::channel('servers.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Individual server channel - user can subscribe to a specific server
Broadcast::channel('server.{serverId}', function ($user, $serverId) {
    return Server::where('id', $serverId)
        ->where('user_id', $user->id)
        ->exists();
});

// Deployment channel - user can subscribe to deployment logs
Broadcast::channel('deployments.{deploymentId}', function ($user, $deploymentId) {
    $deployment = Deployment::find($deploymentId);

    $authorized = $deployment && $deployment->site->server->user_id === $user->id;

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
