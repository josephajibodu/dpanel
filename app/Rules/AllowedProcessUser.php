<?php

namespace App\Rules;

use App\Models\Server;
use App\Models\Site;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Workers and cron jobs may only run as a user FlitOps created, never as
 * root or another system account. A job tied to an isolated site must run
 * as that site's own user (so it can reach the site's files and nothing
 * else); every other job runs as the server user.
 */
class AllowedProcessUser implements ValidationRule
{
    public function __construct(
        private ?Site $site = null,
    ) {}

    /**
     * The rule for a job on this server that's tied to the given site id
     * (or to no site, when the id is empty or isn't one of the server's sites).
     */
    public static function forSiteId(Server $server, mixed $siteId): self
    {
        return new self(self::siteFor($server, $siteId));
    }

    /**
     * The server's site a job with this site id is tied to, if any.
     */
    public static function siteFor(Server $server, mixed $siteId): ?Site
    {
        return is_numeric($siteId) ? $server->sites()->find((int) $siteId) : null;
    }

    /**
     * @return list<string>
     */
    public function users(): array
    {
        return [$this->site?->systemUser() ?? config('server.user')];
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (in_array($value, $this->users(), true)) {
            return;
        }

        if ($this->site?->isIsolated()) {
            $fail('This site runs as its own user, so the user must be "'.$this->site->systemUser().'".');

            return;
        }

        $fail('The user must be "'.implode('" or "', $this->users()).'". Running as root or other system users is not allowed.');
    }
}
