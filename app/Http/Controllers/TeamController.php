<?php

namespace App\Http\Controllers;

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteTeam;
use App\Actions\Teams\UpdateTeamName;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamNameRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function show(Team $team): Response
    {
        $this->authorize('view', $team);

        return $this->renderTeamSettings($team);
    }

    public function showCurrent(): Response|RedirectResponse
    {
        $team = auth()->user()->currentTeam;

        if (! $team) {
            return redirect('/dashboard');
        }

        $this->authorize('view', $team);

        return $this->renderTeamSettings($team);
    }

    private function renderTeamSettings(Team $team): Response
    {
        $team->load(['owner', 'users', 'invitations']);

        return Inertia::render('settings/team', [
            'team' => $team,
            'members' => $team->users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
            ]),
            'invitations' => $team->invitations,
        ]);
    }

    public function slugSuggestion(Request $request): JsonResponse
    {
        $name = $request->string('name')->toString();
        $base = Str::slug($name);

        if ($base === '') {
            return response()->json(['slug' => '']);
        }

        $slug = $base;
        $i = 2;

        while (Team::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return response()->json(['slug' => $slug]);
    }

    public function store(StoreTeamRequest $request, CreateTeam $action): RedirectResponse
    {
        $team = $action->execute(
            user: $request->user(),
            name: $request->validated('name'),
            slug: $request->validated('slug') ?: null,
        );

        $request->user()->switchTeam($team);

        return redirect()
            ->route('servers.index', $team)
            ->with('success', 'Team created.');
    }

    public function update(UpdateTeamNameRequest $request, Team $team, UpdateTeamName $action): RedirectResponse
    {
        $action->execute($team, $request->validated('name'), $request->validated('slug') ?: null);

        $team->refresh();

        return redirect()
            ->route('teams.show', $team)
            ->with('success', 'Team settings updated.');
    }

    public function destroy(Team $team, DeleteTeam $action): RedirectResponse
    {
        $this->authorize('delete', $team);

        $user = auth()->user();

        $action->execute($user, $team);

        $user->refresh();

        return redirect()
            ->route('servers.index', $user->currentTeam)
            ->with('success', 'Team deleted.');
    }
}
