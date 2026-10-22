<?php

use App\Models\DatabaseUser;
use App\Models\EnvironmentVariable;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Models\StorageProvider;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Model;

it('leaves secret fields out when a model is serialised', function (string $modelClass, array $secrets) {
    /** @var Model $model */
    $model = (new $modelClass)->forceFill($secrets);

    expect($model->toArray())->not->toHaveKeys(array_keys($secrets))
        ->and($model->toJson())->not->toContain('top-secret');

    foreach ($secrets as $attribute => $value) {
        expect($model->getAttribute($attribute))->toBe($value);
    }
})->with([
    'server passwords' => [Server::class, ['sudo_password' => 'top-secret-1', 'database_password' => 'top-secret-2']],
    'database user password' => [DatabaseUser::class, ['password' => 'top-secret']],
    'environment variable value' => [EnvironmentVariable::class, ['value' => 'top-secret']],
    'server credential value' => [ServerCredential::class, ['value' => 'top-secret']],
    'site env file and webhook secret' => [Site::class, ['env_content' => 'APP_KEY=top-secret', 'webhook_secret' => 'top-secret-hook']],
    'source control tokens' => [SourceControlAccount::class, ['token' => 'top-secret-1', 'refresh_token' => 'top-secret-2']],
    'provider account credentials' => [ProviderAccount::class, ['credentials' => ['api_token' => 'top-secret']]],
    'storage provider credentials' => [StorageProvider::class, ['credentials' => ['secret_access_key' => 'top-secret']]],
    'team invitation token' => [TeamInvitation::class, ['token' => 'top-secret']],
]);
