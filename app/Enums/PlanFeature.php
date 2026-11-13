<?php

namespace App\Enums;

/**
 * Something only some plans include. Which plans include it is set in
 * {@see Plan::features()}.
 */
enum PlanFeature: string
{
    case TeamMembers = 'team_members';
    case ExtraTeams = 'extra_teams';
    case DatabaseBackups = 'database_backups';
    case Monitoring = 'monitoring';
    case Octane = 'octane';

    /**
     * What the user is told when their plan doesn't include this feature.
     */
    public function upgradeMessage(): string
    {
        $feature = match ($this) {
            self::TeamMembers => 'Inviting team members',
            self::ExtraTeams => 'Creating more teams',
            self::DatabaseBackups => 'Backing up databases',
            self::Monitoring => 'Uptime and certificate monitoring',
            self::Octane => 'Running a site on Laravel Octane',
        };

        $plans = implode(' or ', array_map(fn (Plan $plan): string => $plan->label(), Plan::including($this)));

        return "{$feature} needs the {$plans} plan.";
    }

    /**
     * What a control for this feature shows while it's locked: why, and the
     * cheapest plan that unlocks it.
     *
     * @return array{message: string, plan: string}
     */
    public function lock(): array
    {
        return ['message' => $this->upgradeMessage(), 'plan' => Plan::including($this)[0]->label()];
    }
}
