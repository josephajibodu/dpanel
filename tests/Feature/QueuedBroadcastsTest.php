<?php

use App\Events\RealtimeDiagnosticMessage;
use App\Events\ServerSitesUpdated;
use App\Models\Server;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<string, array{class-string}>
 */
function broadcastEventClasses(): array
{
    return collect(glob(__DIR__.'/../../app/Events/*.php'))
        ->map(fn (string $path) => 'App\\Events\\'.basename($path, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, ShouldBroadcast::class))
        ->reject(fn (string $class) => $class === RealtimeDiagnosticMessage::class)
        ->mapWithKeys(fn (string $class) => [class_basename($class) => [$class]])
        ->all();
}

it('queues the broadcast on the dedicated broadcasts queue', function (string $eventClass) {
    $event = (new ReflectionClass($eventClass))->newInstanceWithoutConstructor();

    expect($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastQueue())->toBe('broadcasts');
})->with(broadcastEventClasses());

it('keeps the realtime diagnostic ping synchronous so Reverb errors surface to the caller', function () {
    expect(is_subclass_of(RealtimeDiagnosticMessage::class, ShouldBroadcastNow::class))->toBeTrue();
});

it('does not contact the broadcaster inline when the event is fired', function () {
    Queue::fake();

    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldNotReceive('broadcast');
    Broadcast::extend('failing', fn () => $broadcaster);
    config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);

    $server = Server::factory()->create();

    event(new ServerSitesUpdated($server));

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof ServerSitesUpdated);
});
