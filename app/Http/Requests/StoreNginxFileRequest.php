<?php

namespace App\Http\Requests;

use App\Enums\NginxFileSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNginxFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer', 'exists:site_domains,id'],
            'section' => ['required', 'string', Rule::enum(NginxFileSection::class)],
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\.]+\.conf$/'],
            'sub_path' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\/]+$/'],
            'content' => ['required', 'string', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The filename must end in .conf and contain only letters, numbers, hyphens, underscores, or dots.',
            'sub_path.regex' => 'The sub-path may only contain letters, numbers, hyphens, underscores, and forward slashes.',
        ];
    }

    public function resolvedPath(): string
    {
        $section = $this->validated('section');
        $name = $this->validated('name');
        $subPath = $this->validated('sub_path');

        if ($subPath) {
            return "{$section}/{$subPath}/{$name}";
        }

        return "{$section}/{$name}";
    }
}
