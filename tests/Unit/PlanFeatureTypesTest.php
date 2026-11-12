<?php

use App\Enums\Plan;
use App\Enums\PlanFeature;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the TypeScript PlanFeature union in step with the PHP enum', function () {
    $source = file_get_contents(resource_path('js/types/plan.ts'));

    preg_match('/export type PlanFeature =(.*?);/s', $source, $union);
    preg_match_all("/'([a-z_]+)'/", $union[1] ?? '', $values);

    expect($values[1])->toEqualCanonicalizing(
        array_map(fn (PlanFeature $feature): string => $feature->value, PlanFeature::cases())
    );
});

it('keeps the TypeScript PlanKey union in step with the PHP enum', function () {
    $source = file_get_contents(resource_path('js/types/plan.ts'));

    preg_match('/export type PlanKey =(.*?);/s', $source, $union);
    preg_match_all("/'([a-z_]+)'/", $union[1] ?? '', $values);

    expect($values[1])->toEqualCanonicalizing(
        array_map(fn (Plan $plan): string => $plan->value, Plan::cases())
    );
});
