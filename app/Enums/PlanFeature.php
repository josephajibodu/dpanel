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
        };

        $plans = implode(' or ', array_map(fn (Plan $plan): string => $plan->label(), Plan::including($this)));

        return "{$feature} needs the {$plans} plan.";
    }
}
