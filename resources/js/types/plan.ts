/** Mirrors App\Enums\Plan; PlanFeatureTypesTest fails if they drift apart. */
export type PlanKey = 'hobby' | 'pro' | 'team';

/** Mirrors App\Enums\PlanFeature; PlanFeatureTypesTest fails if they drift apart. */
export type PlanFeature =
    | 'team_members'
    | 'extra_teams'
    | 'database_backups'
    | 'monitoring'
    | 'octane';

/** Why a control is locked on the current plan, and the cheapest plan that unlocks it. */
export interface PlanLock {
    message: string;
    plan: string;
}

/** What the current team's plan (its owner's) leaves out. Presentation only; the server enforces every limit. */
export interface Plan {
    label: string;
    /** Each feature the plan doesn't include. */
    notIncluded: Partial<Record<PlanFeature, PlanLock>>;
    /** Set once the plan has no room for another server. */
    serverLimit: PlanLock | null;
}
