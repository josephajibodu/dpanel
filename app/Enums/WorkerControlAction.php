<?php

namespace App\Enums;

enum WorkerControlAction: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';

    public function targetStatus(): string
    {
        return match ($this) {
            self::Start, self::Restart => 'active',
            self::Stop => 'stopped',
        };
    }
}
