<?php

use App\Rules\ValidCronExpression;

function passesValidCron(string $value): bool
{
    $passes = true;
    (new ValidCronExpression)->validate('frequency', $value, function () use (&$passes) {
        $passes = false;
    });

    return $passes;
}

it('accepts standard preset expressions', function (string $expr) {
    expect(passesValidCron($expr))->toBeTrue();
})->with([
    '* * * * *',
    '*/5 * * * *',
    '*/15 * * * *',
    '*/30 * * * *',
    '0 * * * *',
    '0 0 * * *',
    '0 0 * * 0',
]);

it('accepts valid custom expressions', function (string $expr) {
    expect(passesValidCron($expr))->toBeTrue();
})->with([
    '0 2 * * 1-5',
    '30 8 1 * *',
    '0 0 1,15 * *',
    '*/10 9-17 * * 1-5',
    '0 12 * * 1,3,5',
    '5 4 * * 0',
]);

it('rejects invalid expressions', function (string $expr) {
    expect(passesValidCron($expr))->toBeFalse();
})->with([
    'every minute',
    '@daily',
    '* * * *',
    '* * * * * *',
    '',
    'not a cron',
]);
