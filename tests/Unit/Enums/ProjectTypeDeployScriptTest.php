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

    it('leaves worker restarts out of the editable laravel script, which runs before current is swapped', function () {
        // A worker restarted from here would come straight back up on the
        // old release — see ProjectType::postActivationScript().
        expect(ProjectType::Laravel->defaultDeployScript())->not->toContain('queue:restart');
    });
});

describe('ProjectType::postActivationScript', function () {
    it('restarts queue workers, Horizon and Reverb for laravel sites', function () {
        $script = ProjectType::Laravel->postActivationScript();

        expect($script)->toContain('$PHP artisan queue:restart')
            ->and($script)->toContain('if [ -d vendor/laravel/horizon ]; then $PHP artisan horizon:terminate; fi')
            ->and($script)->toContain('if [ -d vendor/laravel/reverb ]; then $PHP artisan reverb:restart; fi');
    });

    it('has nothing to restart for other project types', function (ProjectType $type) {
        expect($type->postActivationScript())->toBe('');
    })->with([
        [ProjectType::Symfony],
        [ProjectType::PhpGeneric],
        [ProjectType::StaticHtml],
        [ProjectType::WordPress],
    ]);
});
