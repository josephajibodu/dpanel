<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * The plan a user is on. A team takes its limits from its owner's plan, so
 * one plan covers every team that person owns, and its server limit counts
 * the servers in all of them. The limits mirror the comparison table on the
 * pricing page.
 */
enum Plan: string
{
    case Hobby = 'hobby';
    case Pro = 'pro';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Hobby => 'Hobby',
            self::Pro => 'Pro',
            self::Team => 'Team',
        };
    }

    /**
     * The most servers the owner may have across all their teams, or null
     * for no limit.
     */
    public function serverLimit(): ?int
    {
        return match ($this) {
            self::Hobby => 1,
            self::Pro, self::Team => null,
        };
    }

    /**
     * @return list<PlanFeature>
     */
    public function features(): array
    {
        return match ($this) {
            self::Hobby => [],
            self::Pro => [PlanFeature::DatabaseBackups, PlanFeature::Monitoring],
            self::Team => PlanFeature::cases(),
        };
    }

    public function includes(PlanFeature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * @return list<self>
     */
    public static function including(PlanFeature $feature): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $plan): bool => $plan->includes($feature),
        ));
    }

    /**
     * What the user is told when this plan has no room for another server.
     */
    public function serverLimitMessage(): string
    {
        $limit = $this->serverLimit();

        return "The {$this->label()} plan includes {$limit} ".Str::plural('server', $limit)
            .". Upgrade to {$this->serverUpgrade()->label()} to add more.";
    }

    /**
     * What a server button shows while this plan has no room for another
     * server: why, and the plan that lifts the limit.
     *
     * @return array{message: string, plan: string}
     */
    public function serverLimitLock(): array
    {
        return ['message' => $this->serverLimitMessage(), 'plan' => $this->serverUpgrade()->label()];
    }

    /**
     * The cheapest plan that allows more servers than this one.
     */
    private function serverUpgrade(): self
    {
        $limit = $this->serverLimit();

        return collect(self::cases())->first(
            fn (self $plan): bool => $plan->serverLimit() === null || $plan->serverLimit() > $limit,
        );
    }
}
