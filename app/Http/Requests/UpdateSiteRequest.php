<?php

namespace App\Http\Requests;

use App\Models\Server;
use App\Models\Site;
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
            && (! $this->has('auto_deploy') || $this->user()->can('changeDeploySettings', $this->route('site')))
            && (! $this->hasAny(['repository', 'source_control_account_id', 'branch']) || $this->user()->can('changeRepository', $this->route('site')));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_control_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                'required_with:repository',
                // Your own accounts, or the one the site already uses (which may be a teammate's).
                Rule::exists('source_control_accounts', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->orWhere('id', $this->route('site')->source_control_account_id)),
            ],
            'repository' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_.-]+(\/[a-zA-Z0-9_.-]+)+$/'],
            'directory' => ['sometimes', 'string', 'max:255', 'regex:'.Site::DIRECTORY_PATTERN],
            'branch' => ['sometimes', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\.\/-]+$/'],
            'php_version' => ['sometimes', 'string', Rule::in($this->getInstalledPhpVersions())],
            'auto_deploy' => ['sometimes', 'boolean'],
            'single_page_app' => ['sometimes', 'boolean'],
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
            'repository.regex' => 'Repository should be in format: owner/repository.',
            'directory.regex' => 'Directory must start with / and contain only letters, numbers, dots, underscores, and hyphens, without "..".',
            'branch.regex' => 'Branch name contains invalid characters.',
            'php_version.in' => 'The selected PHP version is not installed on this server.',
        ];
    }

    /** @return array<int, string> */
    private function getInstalledPhpVersions(): array
    {
        $server = $this->route('server');

        if (! $server instanceof Server) {
            return ['8.1', '8.2', '8.3', '8.4', '8.5'];
        }

        $versions = $server->installedServices()
            ->where('type', 'php')
            ->get()
            ->map(fn ($s) => $s->installed_version ?? $s->version)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ! empty($versions) ? $versions : ['8.1', '8.2', '8.3', '8.4', '8.5'];
    }
}
