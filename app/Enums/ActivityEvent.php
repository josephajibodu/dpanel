<?php

namespace App\Enums;

/**
 * Every kind of action the team activity log records. Add a case here
 * for each new thing worth tracking.
 */
enum ActivityEvent: string
{
    case SiteEnvironmentUpdated = 'site.environment.updated';

    public function label(): string
    {
        return match ($this) {
            self::SiteEnvironmentUpdated => 'Environment updated',
        };
    }
}
