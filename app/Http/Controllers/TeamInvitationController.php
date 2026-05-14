<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    public function accept(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        if (! $request->user()) {
            return Inertia::render('teams/accept-invitation', [
                'invitation' => $invitation->load('team'),
                'token' => $token,
            ]);
        }

        return $this->handleAccept($request, $invitation);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        return $this->handleAccept($request, $invitation);
    }

    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        $this->authorize('update', $team);

        $invitation->delete();

        return redirect()
            ->back()
            ->with('success', 'Invitation cancelled.');
    }

    private function handleAccept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $user = $request->user();
        $team = $invitation->team;

        if ($user->email !== $invitation->email) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'This invitation was sent to a different email address.');
        }

        if (! $team->hasUser($user)) {
            $team->users()->attach($user, ['role' => $invitation->role]);
        }

        $invitation->delete();

        $user->switchTeam($team);

        return redirect()
            ->route('servers.index')
            ->with('success', "You have joined {$team->name}.");
    }
}
