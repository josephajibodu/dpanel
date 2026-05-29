<?php

use App\Services\ServerNameGenerator;

it('generates a lowercase adjective-noun name that passes server name validation', function () {
    $name = (new ServerNameGenerator)->generate();

    expect($name)->toMatch('/^[a-z]+-[a-z]+$/')
        ->and($name)->toMatch('/^[a-zA-Z0-9-]+$/');
});

it('produces varied names across many calls', function () {
    $generator = new ServerNameGenerator;

    $names = collect(range(1, 100))->map(fn () => $generator->generate());

    expect($names->unique()->count())->toBeGreaterThan(1);
});
