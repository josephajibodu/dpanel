<?php

use App\Models\Server;
use Illuminate\Support\Facades\Broadcast;

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
use App\Models\Deployment;

Broadcast::channel('deployments.{deploymentId}', function ($user, $deploymentId) {
    $deployment = Deployment::find($deploymentId);

    return $deployment && $deployment->site->server->user_id === $user->id;
});
