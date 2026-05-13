<?php

namespace App\Providers;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\SimpleDeploymentStrategy;
use App\Services\Providers\ProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class);
        $this->app->bind(DeploymentStrategy::class, SimpleDeploymentStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHttpClientLogging();

        Route::bind('site_domain', function (string $value, \Illuminate\Routing\Route $route) {
            $site = $route->parameter('site');
            $siteId = match (true) {
                $site instanceof Site => $site->id,
                is_numeric($site) => (int) $site,
                default => abort(404),
            };

            return SiteDomain::query()
                ->where('site_id', $siteId)
                ->where('ulid', $value)
                ->firstOrFail();
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function configureHttpClientLogging(): void
    {
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            Log::channel('outbound-api')->info('Outgoing API Request', [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'headers' => $this->redactSensitiveHeaders($event->request->headers()),
            ]);
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            Log::channel('outbound-api')->info('Outgoing API Response', [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'status' => $event->response->status(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function redactSensitiveHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'x-api-key', 'x-auth-token', 'cookie', 'set-cookie'];

        return collect($headers)
            ->map(fn ($value, $key) => in_array(strtolower($key), $sensitive) ? ['[REDACTED]'] : $value)
            ->all();
    }
}
