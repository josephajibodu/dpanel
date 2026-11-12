/** Mirrors App\Enums\PlanFeature; PlanFeatureTypesTest fails if they drift apart. */
export type PlanFeature =
    | 'team_members'
    | 'extra_teams'
    | 'database_backups'
    | 'monitoring';

/** What the current team's plan (its owner's) leaves out. Presentation only; the server enforces every limit. */
export interface Plan {
    label: string;
    /** Each feature the plan doesn't include, with the upgrade message to show instead. */
    notIncluded: Partial<Record<PlanFeature, string>>;
    /** Why no more servers can be added, or null while there is room. */
    serverLimitMessage: string | null;
}
