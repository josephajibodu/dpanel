<?php

use App\Enums\ProjectType;

describe('ProjectType::defaultDeployScript', function () {
    it('never runs git itself — cloning is the zero-downtime deploy strategy\'s job', function (ProjectType $type) {
        $script = $type->defaultDeployScript();

        // Every deploy clones into a brand new releases/{ulid} directory (see
        // ZeroDowntimeDeploymentStrategy::prepend()), so there's no old state
        // in that directory to conflict with — no fetch/reset/pull needed here.
        expect($script)->not->toContain('git ');
    })->with([
        [ProjectType::Laravel],
        [ProjectType::Symfony],
        [ProjectType::PhpGeneric],
        [ProjectType::StaticHtml],
        [ProjectType::WordPress],
    ]);

    it('keeps the laravel build pipeline intact', function () {
        $script = ProjectType::Laravel->defaultDeployScript();

        expect($script)->toContain('$COMPOSER install --no-dev');
        expect($script)->toContain('npm run build');
        expect($script)->toContain('artisan migrate --force');
        expect($script)->toContain('artisan config:cache');
    });

    it('restarts queue workers after a laravel deploy so they pick up new code', function () {
        $script = ProjectType::Laravel->defaultDeployScript();

        expect($script)->toContain('artisan queue:restart');

        // It must run after the code/config are in place, not before —
        // otherwise a worker could restart into a half-deployed release.
        $restartPosition = strpos($script, 'artisan queue:restart');
        $configCachePosition = strpos($script, 'artisan config:cache');

        expect($restartPosition)->toBeGreaterThan($configCachePosition);
    });
});
