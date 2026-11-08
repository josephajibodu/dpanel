<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('site'))
            && (! $this->has('auto_deploy') || $this->user()->can('deploy', $this->route('site')));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'directory' => ['sometimes', 'string', 'max:255', 'regex:/^\/[a-zA-Z0-9\/_-]*$/'],
            'branch' => ['sometimes', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\.\/-]+$/'],
            'php_version' => ['sometimes', 'string', Rule::in($this->getInstalledPhpVersions())],
            'auto_deploy' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'directory.regex' => 'Directory must start with / and contain only letters, numbers, underscores, and hyphens.',
            'branch.regex' => 'Branch name contains invalid characters.',
            'php_version.in' => 'The selected PHP version is not installed on this server.',
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
