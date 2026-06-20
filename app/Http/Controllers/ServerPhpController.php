<?php

namespace App\Http\Controllers;

use App\Actions\ServerPhp\GetPhpInfo;
use App\Actions\ServerPhp\SetDefaultPhpVersion;
use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Enums\ServiceType;
use App\Http\Requests\InstallPhpVersionRequest;
use App\Http\Requests\SetDefaultPhpVersionRequest;
use App\Http\Requests\UpdatePhpSettingsRequest;
use App\Http\Resources\ServerResource;
use App\Jobs\InstallPhpVersionJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerPhpController extends Controller
{
    private const AVAILABLE_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];

    public function __construct(
        private GetPhpInfo $getPhpInfo
    ) {}

    public function index(Team $team, Server $server): Response
    {
        $this->authorize('view', $server);

        $phpServices = $server->installedServices()
            ->where('type', 'php')
            ->orderBy('version')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'version' => $s->version,
                'installed_version' => $s->installed_version,
                'is_default' => $s->is_default,
                'status' => $s->status->value,
            ])
            ->values()
            ->all();

        $defaultVersion = $server->getDefaultPhpVersion() ?? '';

        $installedVersions = collect($phpServices)
            ->map(fn ($s) => $s['installed_version'] ?? $s['version'])
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return Inertia::render('servers/php/index', [
            'server' => new ServerResource($server),
            'serverIsReady' => $server->isReady(),
            'phpServices' => $phpServices,
            'installedVersions' => $installedVersions,
            'defaultVersion' => $defaultVersion,
            'availableVersions' => self::AVAILABLE_VERSIONS,
            'settings' => Inertia::defer(fn () => $this->getPhpInfo->execute($server)),
        ]);
    }

    public function updateSettings(UpdatePhpSettingsRequest $request, Team $team, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        if (! $server->isReady()) {
            return redirect()
                ->back()
                ->with('error', 'Server must be active and connected to update PHP settings.');
        }

        try {
            app(UpdatePhpSettings::class)->execute($server, $request->validated());
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }

        $version = $server->getDefaultPhpVersion();
        if ($version !== null && $version !== '') {
            GetPhpInfo::invalidateCache($server->id, $version);
        }

        return redirect()
            ->back()
            ->with('success', 'PHP settings updated.');
    }

    public function installVersion(InstallPhpVersionRequest $request, Team $team, Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

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
        $this->authorize('view', $server);

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
}
