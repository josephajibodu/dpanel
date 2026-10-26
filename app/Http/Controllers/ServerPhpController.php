<?php

namespace App\Http\Controllers;

use App\Actions\ServerPhp\InstallPhpVersion;
use App\Actions\ServerPhp\SetDefaultPhpVersion;
use App\Enums\PhpExtension;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Http\Requests\ChangePhpExtensionRequest;
use App\Http\Requests\InstallPhpVersionRequest;
use App\Http\Requests\SetDefaultPhpVersionRequest;
use App\Http\Requests\UpdatePhpSettingsRequest;
use App\Http\Resources\ServerResource;
use App\Jobs\ChangePhpExtensionJob;
use App\Jobs\InstallPhpVersionJob;
use App\Jobs\UpdatePhpSettingsJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerPhpController extends Controller
{
    private const AVAILABLE_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];

    public function index(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);
        abort_unless($server->hasPhp(), 404);

        $phpServicesCollection = $server->installedServices()
            ->where('type', 'php')
            ->orderBy('version')
            ->get();

        $phpServices = $phpServicesCollection
            ->map(fn ($s) => [
                'id' => $s->id,
                'version' => $s->version,
                'installed_version' => $s->installed_version,
                'is_default' => $s->is_default,
                'status' => $s->status->value,
                'extensions' => $s->type_data['extensions'] ?? [],
                'extensions_sync_status' => $s->type_data['extensions_sync_status'] ?? null,
                'extensions_sync_error' => $s->type_data['extensions_sync_error'] ?? null,
                'extensions_pending' => $s->type_data['extensions_pending'] ?? null,
            ])
            ->values()
            ->all();

        $defaultVersion = $server->getDefaultPhpVersion() ?? '';

        $installedVersions = $phpServicesCollection
            ->map(fn ($s) => $s->installed_version ?? $s->version)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $defaultService = $phpServicesCollection->firstWhere('is_default', true);
        $settings = $defaultService?->type_data['settings'] ?? null;
        $settingsSyncStatus = $defaultService?->type_data['settings_sync_status'] ?? null;
        $settingsSyncError = $defaultService?->type_data['settings_sync_error'] ?? null;

        return Inertia::render('servers/php/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'phpServices' => $phpServices,
            'installedVersions' => $installedVersions,
            'defaultVersion' => $defaultVersion,
            'availableVersions' => self::AVAILABLE_VERSIONS,
            'settings' => $settings,
            'settingsSyncStatus' => $settingsSyncStatus,
            'settingsSyncError' => $settingsSyncError,
            'availableExtensions' => collect(PhpExtension::cases())
                ->map(fn (PhpExtension $extension): array => [
                    'value' => $extension->value,
                    'label' => $extension->label(),
                    'description' => $extension->description(),
                ])
                ->all(),
            'includedExtensions' => InstallPhpVersion::EXTENSION_PACKAGES,
        ]);
    }

    public function updateSettings(UpdatePhpSettingsRequest $request, Team $team, Server $server): RedirectResponse
    {
        abort_unless($server->hasPhp(), 404);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to update PHP settings.');
        }

        $version = $server->getDefaultPhpVersion();
        if ($version === null || $version === '') {
            return redirect()
                ->back()
                ->with('error', 'No default PHP version configured.');
        }

        $phpService = $server->service(ServiceType::Php, $version);
        if ($phpService === null) {
            return redirect()
                ->back()
                ->with('error', "PHP {$version} service not found on this server.");
        }

        $phpService->update([
            'type_data' => array_merge($phpService->type_data ?? [], [
                'settings' => $request->validated(),
                'settings_sync_status' => 'pending',
                'settings_sync_error' => null,
            ]),
        ]);

        UpdatePhpSettingsJob::dispatch($phpService);

        return redirect()
            ->back()
            ->with('success', 'PHP settings queued for update.');
    }

    public function installVersion(InstallPhpVersionRequest $request, Team $team, Server $server): RedirectResponse
    {
        abort_unless($server->hasPhp(), 404);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to install PHP versions.');
        }

        $version = $request->validated('version');

        if ($server->service(ServiceType::Php, $version) !== null) {
            return redirect()
                ->back()
                ->with('error', "PHP {$version} is already installed.");
        }

        try {
            $phpService = $server->createService(ServiceType::Php, $version, false);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }

        InstallPhpVersionJob::dispatch($phpService);

        return redirect()
            ->back()
            ->with('success', "PHP {$version} is being installed.");
    }

    public function setDefaultVersion(SetDefaultPhpVersionRequest $request, Team $team, Server $server): RedirectResponse
    {
        abort_unless($server->hasPhp(), 404);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to set the default PHP version.');
        }

        $version = $request->validated('version');
        $upgradeSites = (bool) $request->validated('upgrade_sites', false);

        if ($server->service(ServiceType::Php, $version) === null) {
            return redirect()
                ->back()
                ->with('error', "PHP {$version} is not installed on this server.");
        }

        try {
            app(SetDefaultPhpVersion::class)->execute($server, $version, $upgradeSites);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }

        $message = $upgradeSites
            ? "Default PHP version set to {$version} and all sites are being updated."
            : "Default PHP version set to {$version}.";

        return redirect()
            ->back()
            ->with('success', $message);
    }

    public function installExtension(ChangePhpExtensionRequest $request, Team $team, Server $server): RedirectResponse
    {
        return $this->changeExtension($request, $server, true);
    }

    public function removeExtension(ChangePhpExtensionRequest $request, Team $team, Server $server): RedirectResponse
    {
        return $this->changeExtension($request, $server, false);
    }

    private function changeExtension(ChangePhpExtensionRequest $request, Server $server, bool $install): RedirectResponse
    {
        abort_unless($server->hasPhp(), 404);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to change PHP extensions.');
        }

        $version = $request->validated('version');
        $extension = PhpExtension::from($request->validated('extension'));
        $phpService = $server->service(ServiceType::Php, $version);

        if ($phpService === null || $phpService->status !== ServiceStatus::Active) {
            return redirect()
                ->back()
                ->with('error', "PHP {$version} is not installed on this server.");
        }

        if (in_array($phpService->type_data['extensions_sync_status'] ?? null, ['pending', 'syncing'], true)) {
            return redirect()
                ->back()
                ->with('error', "Another extension change is already in progress for PHP {$version}.");
        }

        $phpService->update([
            'type_data' => array_merge($phpService->type_data ?? [], [
                'extensions_sync_status' => 'pending',
                'extensions_sync_error' => null,
                'extensions_pending' => [
                    'extension' => $extension->value,
                    'action' => $install ? 'install' : 'remove',
                ],
            ]),
        ]);

        ChangePhpExtensionJob::dispatch($phpService, $extension, $install);

        $verb = $install ? 'Installing' : 'Removing';

        return redirect()
            ->back()
            ->with('success', "{$verb} {$extension->label()} for PHP {$version}.");
    }
}
