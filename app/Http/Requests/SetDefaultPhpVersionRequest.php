<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetDefaultPhpVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('server'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', Rule::in($this->getInstalledPhpVersions())],
            'upgrade_sites' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Please select a PHP version.',
            'version.in' => 'The selected PHP version is not installed on this server.',
        ];
    }

    /** @return array<int, string> */
    private function getInstalledPhpVersions(): array
    {
        $server = $this->route('server');

        if (! $server instanceof Server) {
            return ['8.1', '8.2', '8.3', '8.4'];
        }

        $versions = $server->installedServices()
            ->where('type', 'php')
            ->get()
            ->map(fn ($s) => $s->installed_version ?? $s->version)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ! empty($versions) ? $versions : ['8.1', '8.2', '8.3', '8.4'];
    }
}
