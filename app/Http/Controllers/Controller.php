<?php

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Enums\PlanFeature;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Refuse the request when the plan doesn't include the feature, telling
     * the user which plan does. The UI swaps these controls for an upgrade
     * note, so this is only reached by going around it.
     */
    protected function ensurePlanIncludes(Plan $plan, PlanFeature $feature): void
    {
        abort_unless($plan->includes($feature), 403, $feature->upgradeMessage());
    }
}
