<?php

namespace App\Providers;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Notifications\Welcome;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\ZeroDowntimeDeploymentStrategy;
use App\Services\Providers\ProviderManager;
use App\Services\Storage\StorageProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class);
        $this->app->singleton(StorageProviderManager::class);
        $this->app->bind(DeploymentStrategy::class, ZeroDowntimeDeploymentStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
        $this->configureErrorPages();
        $this->configureHttpClientLogging();
        $this->configureWelcomeEmail();

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

    /**
     * Add the scheduler and the terminal gateway to the processes started by
     * `php artisan dev`. The gateway skips itself when it isn't configured.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('schedule:work', 'schedule');
        DevCommands::artisan('flitops:terminal-gateway', 'terminal');
    }

    /**
     * Inertia shows any plain HTML error page in a pop-up over the app, so
     * errors are sent back in a form Inertia understands. A form submit or
     * action that fails returns to its page with an error toast, and a page
     * that fails to load shows the error page. With debug on, Laravel's
     * detailed error page is kept so the stack trace still shows locally.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ExceptionResponse|RedirectResponse|null {
            $request = $response->request;
            $status = $response->statusCode();

            if (config('app.debug') || $status < 400) {
                return null;
            }

            if ($request->inertia() && ! $request->isMethod('GET') && $request->hasSession()) {
                return Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => $this->errorToastMessage($response),
                ])->back(303);
            }

            if ($request->inertia() || ($request->isMethod('GET') && ! $request->expectsJson())) {
                return $response->render('error-page', [
                    'status' => $status,
                    'message' => $status === 403 ? $response->exception->getMessage() : '',
                ]);
            }

            return null;
        });
    }

    /**
     * A 403's own message is kept because it was written for the user, such
     * as the plan upgrade message.
     */
    private function errorToastMessage(ExceptionResponse $response): string
    {
        return match ($response->statusCode()) {
            403 => $response->exception->getMessage() ?: "You don't have permission to do that.",
            404 => "We couldn't find that. It may have been deleted.",
            419 => 'Your session timed out. Please try again.',
            429 => 'Too many attempts. Wait a minute and try again.',
            503 => "We're doing some quick maintenance. Please try again in a few minutes.",
            default => 'Something went wrong on our end. Please try again.',
        };
    }

    /**
     * Send the welcome email the first time an account confirms its email
     * address. Confirming a changed address later doesn't send it again.
     */
    protected function configureWelcomeEmail(): void
    {
        Event::listen(Verified::class, function (Verified $event): void {
            $user = $event->user;

            if (! $user instanceof User || $user->welcomed_at !== null) {
                return;
            }

            $user->forceFill(['welcomed_at' => now()])->save();
            $user->notify(new Welcome);
        });
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
