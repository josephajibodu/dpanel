<?php

use App\Concerns\QueuesBroadcasts;
use Illuminate\Support\Facades\File;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/**
 * The production supervisors exactly as Horizon will run them, with
 * `defaults` already merged in.
 *
 * @return array<string, SupervisorOptions>
 */
function horizonProductionSupervisors(): array
{
    return ProvisioningPlan::get('test-master')->toSupervisorOptions()['production']->all();
}

/**
 * Every queue name the app pushes work onto: `default`, every literal
 * `onQueue('...')` in app/, and the queued-broadcast queue.
 *
 * @return list<string>
 */
function queuesTheAppDispatchesTo(): array
{
    $queues = collect(File::allFiles(app_path()))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all("/onQueue\('([^']+)'\)/", $file->getContents(), $matches);

            return $matches[1];
        });

    $broadcastQueue = (new class
    {
        use QueuesBroadcasts;
    })->broadcastQueue();

    return $queues->push('default', $broadcastQueue)->unique()->sort()->values()->all();
}

/**
 * Each job class's own `$timeout`, keyed by class, alongside the queue it
 * picks in its constructor (or `default` when the dispatcher decides).
 *
 * @return array<class-string, array{queue: string, timeout: int}>
 */
function jobTimeoutsAndQueues(): array
{
    return collect(File::files(app_path('Jobs')))
        ->mapWithKeys(function (SplFileInfo $file): array {
            $class = 'App\\Jobs\\'.$file->getBasename('.php');
            $reflection = new ReflectionClass($class);
            $timeout = $reflection->hasProperty('timeout') ? $reflection->getProperty('timeout')->getDefaultValue() : null;

            preg_match("/onQueue\('([^']+)'\)/", $file->getContents(), $match);

            return $timeout === null ? [] : [$class => ['queue' => $match[1] ?? 'default', 'timeout' => $timeout]];
        })
        ->all();
}

it('consumes every queue the app dispatches to', function () {
    $consumed = collect(horizonProductionSupervisors())
        ->flatMap(fn (SupervisorOptions $supervisor) => explode(',', $supervisor->queue))
        ->unique()
        ->all();

    foreach (queuesTheAppDispatchesTo() as $queue) {
        expect($consumed)->toContain($queue);
    }
});

it('gives up on a failing job instead of retrying it forever', function () {
    foreach (horizonProductionSupervisors() as $name => $supervisor) {
        expect($supervisor->maxTries)->toBeGreaterThan(0, "{$name} retries failing jobs forever");
    }
});

it('stops every job before the queue hands it to a second worker', function () {
    $retryAfter = config('queue.connections.redis.retry_after');

    foreach (horizonProductionSupervisors() as $name => $supervisor) {
        expect($supervisor->timeout)->toBeLessThan($retryAfter, "{$name} outlives retry_after");
    }

    foreach (jobTimeoutsAndQueues() as $class => $job) {
        expect($job['timeout'])->toBeLessThan($retryAfter, "{$class} outlives retry_after");
    }
});

it('never lets an auto-balanced supervisor kill a job that is still within its own timeout', function () {
    $autoBalanced = collect(horizonProductionSupervisors())
        ->filter(fn (SupervisorOptions $supervisor) => $supervisor->balance === 'auto');

    foreach ($autoBalanced as $name => $supervisor) {
        $queues = explode(',', $supervisor->queue);

        foreach (jobTimeoutsAndQueues() as $class => $job) {
            if (in_array($job['queue'], $queues, true)) {
                expect($supervisor->timeout)->toBeGreaterThanOrEqual($job['timeout'], "{$name} can kill {$class}");
            }
        }
    }
});
