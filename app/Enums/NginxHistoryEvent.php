<?php

namespace App\Enums;

enum NginxHistoryEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Renamed = 'renamed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Renamed => 'Renamed',
        };
    }
}
