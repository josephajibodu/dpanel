<?php

use Illuminate\Console\Scheduling\Schedule;

it('prunes failed jobs older than a week every day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'queue:prune-failed'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toEndWith('queue:prune-failed --hours=168')
        ->and($event->expression)->toBe('0 0 * * *');
});
