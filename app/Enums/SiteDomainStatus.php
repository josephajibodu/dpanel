<?php

namespace App\Enums;

enum SiteDomainStatus: string
{
    case Active = 'active';
    case Deleting = 'deleting';
}
