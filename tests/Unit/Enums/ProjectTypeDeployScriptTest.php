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
});
