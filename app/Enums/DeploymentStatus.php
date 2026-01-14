<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Finished => 'Finished',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'blue',
            self::Finished => 'green',
            self::Failed => 'red',
            self::Cancelled => 'orange',
        };
    }
}
