<?php

namespace App\Support;

use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use App\Models\Server;
use App\Models\SiteDomain;

class SiteDomainDnsInstructions
{
    /**
     * @return list<array{type: string, name: string, value: string}>
     */
    public static function records(Server $server, SiteDomain $domain): array
    {
        if ($domain->type === SiteDomainType::System) {
            return [];
        }

        $ip = $server->ip_address;
        if (! $ip) {
            return [];
        }

        $isApex = $domain->isApex();
        $recordName = $isApex ? '@' : explode('.', $domain->hostname)[0];

        $records = [];

        if ($domain->wildcard_enabled && $isApex) {
            $records[] = ['type' => 'A', 'name' => '@', 'value' => $ip];
            $records[] = ['type' => 'A', 'name' => '*', 'value' => $ip];
        } else {
            $records[] = ['type' => 'A', 'name' => $recordName, 'value' => $ip];
        }

        if ($domain->effectiveWwwRedirect() !== WwwRedirect::None) {
            $records[] = ['type' => 'A', 'name' => 'www', 'value' => $ip];
        }

        return $records;
    }
}
