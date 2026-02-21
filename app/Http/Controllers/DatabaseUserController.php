<?php

namespace App\Http\Controllers;

use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\DestroyDatabaseUser;
use App\Actions\Database\UpdateDatabaseUser;
use App\Http\Requests\StoreDatabaseUserRequest;
use App\Http\Requests\UpdateDatabaseUserRequest;
use App\Models\DatabaseUser;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

class DatabaseUserController extends Controller
{
    public function store(StoreDatabaseUserRequest $request, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to create database users.');
        }

        app(CreateDatabaseUser::class)->create($server, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Database user is being created.');
    }

    public function update(
        UpdateDatabaseUserRequest $request,
        Server $server,
        DatabaseUser $database_user,
    ): RedirectResponse {
        $this->authorize('view', $server);

        app(UpdateDatabaseUser::class)->update($database_user, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Database user is being updated on the server.');
    }

    public function destroy(Server $server, DatabaseUser $database_user): RedirectResponse
    {
        $this->authorize('view', $server);

        app(DestroyDatabaseUser::class)->delete($database_user);

        return redirect()
            ->back()
            ->with('success', 'Database user is being removed.');
    }
}
