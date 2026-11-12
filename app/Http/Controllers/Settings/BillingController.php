<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /**
     * Show the user's own plan, which covers every team they own, and how
     * many servers they have across those teams.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/billing', [
            'currentPlan' => $user->plan->value,
            'serverCount' => $user->ownedServerCount(),
            'serverLimit' => $user->plan->serverLimit(),
        ]);
    }
}
