<?php

use App\Enums\TeamPermission;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the TypeScript TeamPermission union in step with the PHP enum', function () {
    $source = file_get_contents(resource_path('js/types/team.ts'));

    preg_match('/export type TeamPermission =(.*?);/s', $source, $union);
    preg_match_all("/'([a-z_]+)'/", $union[1] ?? '', $values);

    expect($values[1])->toEqualCanonicalizing(
        array_map(fn (TeamPermission $permission): string => $permission->value, TeamPermission::cases())
    );
});
