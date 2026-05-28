<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamSlugAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $teamParam = $request->route('team');

        // SubstituteBindings may have already resolved the slug to a Team model
        if ($teamParam instanceof Team) {
            $team = $teamParam;
        } else {
            $team = Team::where('slug', $teamParam)->firstOrFail();
        }

        $user = $request->user();

        if (! $user->belongsToTeam($team)) {
            abort(403, 'You do not have access to this team.');
        }

        // Auto-switch current team when following a shared link to a different team
        if ($user->current_team_id !== $team->id) {
            $user->switchTeam($team);
        }

        // Rebind the route parameter with the resolved model so controllers receive Team $team
        $request->route()->setParameter('team', $team);

        return $next($request);
    }
}
