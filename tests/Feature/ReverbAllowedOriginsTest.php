<?php

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Support\Env;

/**
 * Evaluate config/reverb.php against exactly the given environment and
 * return the allowed origins it resolves to.
 *
 * @param  array<string, string>  $environment
 * @return list<string>
 */
function reverbAllowedOriginsWith(array $environment): array
{
    $repositoryProperty = new ReflectionProperty(Env::class, 'repository');
    $originalRepository = $repositoryProperty->getValue();

    $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();

    foreach ($environment as $key => $value) {
        $repository->set($key, $value);
    }

    $repositoryProperty->setValue(null, $repository);

    try {
        return array_values((require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins']);
    } finally {
        $repositoryProperty->setValue(null, $originalRepository);
    }
}

it('falls back to the host of APP_URL, since Reverb compares origins by host', function () {
    expect(reverbAllowedOriginsWith(['APP_URL' => 'https://flitops.example.com']))
        ->toBe(['flitops.example.com']);
});

it('prefers an explicit comma-separated REVERB_ALLOWED_ORIGINS list', function () {
    expect(reverbAllowedOriginsWith([
        'APP_URL' => 'https://flitops.example.com',
        'REVERB_ALLOWED_ORIGINS' => 'flitops.example.com, *.flitops.example.com',
    ]))->toBe(['flitops.example.com', '*.flitops.example.com']);
});

it('falls back to localhost when neither is set', function () {
    expect(reverbAllowedOriginsWith([]))->toBe(['localhost']);
});
