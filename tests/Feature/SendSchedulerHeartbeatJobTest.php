<?php

use App\Jobs\SendSchedulerHeartbeatJob;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('pings the configured heartbeat url', function () {
    config(['services.heartbeat.url' => 'https://hc-ping.com/abc-123']);
    Http::fake();

    (new SendSchedulerHeartbeatJob)->handle();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.com/abc-123'
        && $request->method() === 'GET');
});

it('does nothing when no heartbeat url is configured', function () {
    config(['services.heartbeat.url' => null]);
    Http::fake();

    (new SendSchedulerHeartbeatJob)->handle();

    Http::assertNothingSent();
});

it('logs instead of failing when the monitor is unreachable', function () {
    config(['services.heartbeat.url' => 'https://hc-ping.com/abc-123']);
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));
    Log::spy();
    Log::shouldReceive('channel')->andReturnSelf();

    (new SendSchedulerHeartbeatJob)->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'Connection timed out'),
    );
});

it('logs when the monitor responds with an error', function () {
    config(['services.heartbeat.url' => 'https://hc-ping.com/abc-123']);
    Http::fake(['*' => Http::response('', 404)]);
    Log::spy();
    Log::shouldReceive('channel')->andReturnSelf();

    (new SendSchedulerHeartbeatJob)->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'HTTP 404'),
    );
});

it('is scheduled every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event instanceof CallbackEvent
            && $event->description === SendSchedulerHeartbeatJob::class);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});

it('has a single try and a 30-second timeout', function () {
    $job = new SendSchedulerHeartbeatJob;

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30);
});
