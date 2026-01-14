<?php

namespace App\Providers;

use App\Services\Providers\ProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHttpClientLogging();
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
                'headers' => $event->request->headers(),
                'body' => $event->request->data(),
            ]);
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            Log::channel('outbound-api')->info('Outgoing API Response', [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'status' => $event->response->status(),
                'headers' => $event->response->headers(),
                'body' => $event->response->body(),
            ]);
        });
    }
}
