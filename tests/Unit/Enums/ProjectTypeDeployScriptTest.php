<?php

use App\Enums\ProjectType;

describe('ProjectType::defaultDeployScript', function () {
    it('uses git fetch + hard reset instead of git pull for every project type', function (ProjectType $type) {
        $script = $type->defaultDeployScript();

        // Hard reset against the remote ref is the safe pattern: it cannot be
        // blocked by uncommitted server-side edits the way `git pull` can.
        expect($script)->toContain('git fetch origin $BRANCH');
        expect($script)->toContain('git reset --hard origin/$BRANCH');
        expect($script)->not->toContain('git pull origin $BRANCH');
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
