<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Accepts only a publicly routable IPv4 or IPv6 address, so FlitOps never
 * connects to its own machine, its internal network or the cloud metadata
 * endpoint. Self-hosted installs can allow private addresses through the
 * `server.allow_private_addresses` config switch.
 */
class PublicIpAddress implements ValidationRule
{
    /**
     * Ranges that PHP's global-range filter still lets through: multicast,
     * and NAT64 prefixes that can map onto internal IPv4 addresses.
     *
     * @var list<string>
     */
    private const EXTRA_BLOCKED_RANGES = [
        '224.0.0.0/4',
        'ff00::/8',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
    ];

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            $fail('Please enter a valid IPv4 or IPv6 address, for example 203.0.113.10.');

            return;
        }

        if (config('server.allow_private_addresses')) {
            return;
        }

        $isPublic = filter_var(
            $value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;

        if (! $isPublic || IpUtils::checkIp($value, self::EXTRA_BLOCKED_RANGES)) {
            $fail('Please enter the server\'s public IP address. Local, private and reserved addresses (such as 127.0.0.1, 10.x.x.x, 192.168.x.x or 169.254.x.x) are not allowed.');
        }
    }
}
