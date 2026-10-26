<?php

use Illuminate\Foundation\DevCommands;

/**
 * The process name => command pairs `php artisan dev` will start.
 *
 * @return array<string, string>
 */
function devProcesses(): array
{
    return collect(DevCommands::commands())
        ->mapWithKeys(fn (array $devCommand): array => [$devCommand['name'] => $devCommand['command']])
        ->all();
}

it('starts every process the app needs locally', function () {
    expect(array_keys(devProcesses()))->toEqualCanonicalizing([
        'server', 'horizon', 'reverb', 'schedule', 'terminal', 'logs', 'vite',
    ]);
});

it('runs the scheduler', function () {
    expect(devProcesses()['schedule'])->toBe('php artisan schedule:work');
});

it('runs the terminal gateway', function () {
    expect(devProcesses()['terminal'])->toBe('php artisan flitops:terminal-gateway');
});

it('lets Horizon process jobs instead of a plain queue worker', function () {
    expect(devProcesses())
        ->toHaveKey('horizon')
        ->not->toHaveKey('queue');
});

it('points composer dev at php artisan dev', function () {
    $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts'];

    expect($scripts['dev'])->toContain('@php artisan dev')
        ->and($scripts)->not->toHaveKey('dev:horizon');
});
