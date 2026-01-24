<?php

namespace App\Http\Requests;

use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'server_id' => [
                'required',
                'integer',
                Rule::exists('servers', 'id')->where('user_id', $this->user()->id),
            ],
            'domain' => [
                'nullable',
                'required_without:site_name',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/',
            ],
            'site_name' => [
                'nullable',
                'required_without:domain',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/',
            ],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => [
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/',
            ],
            'directory' => ['sometimes', 'string', 'max:255', 'regex:/^\/[a-zA-Z0-9\/_-]*$/'],
            'repository' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_\.-]+$/',
            ],
            'source_control_account_id' => [
                'nullable',
                'integer',
                Rule::exists('source_control_accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'repository_provider' => ['sometimes', 'string', Rule::enum(RepositoryProvider::class)],
            'branch' => ['sometimes', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\.\/-]+$/'],
            'project_type' => ['sometimes', 'string', Rule::enum(ProjectType::class)],
            'php_version' => ['sometimes', 'string', Rule::in(['8.1', '8.2', '8.3', '8.4'])],
            'package_manager' => ['nullable', 'string', Rule::in(['none', 'npm', 'pnpm', 'yarn', 'bun'])],
            'build_command' => ['nullable', 'string', 'max:500'],
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
            'server_id.required' => 'Please select a server.',
            'server_id.exists' => 'The selected server is invalid or does not belong to you.',
            'domain.regex' => 'Please enter a valid domain name (e.g., example.com).',
            'site_name.regex' => 'Site name can only contain letters, numbers, and hyphens.',
            'package_manager.in' => 'Package manager must be one of: none, npm, pnpm, yarn, bun.',
            'aliases.*.regex' => 'Please enter valid domain aliases.',
            'directory.regex' => 'Directory must start with / and contain only letters, numbers, underscores, and hyphens.',
            'repository.regex' => 'Repository should be in format: username/repository.',
            'branch.regex' => 'Branch name contains invalid characters.',
        ];
    }
}
