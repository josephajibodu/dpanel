<?php

namespace App\Http\Requests;

use App\Enums\NginxFileSection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNginxFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('editWebServerConfig', $this->route('site'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer', 'exists:site_domains,id'],
            'section' => ['required', 'string', Rule::enum(NginxFileSection::class)->except([NginxFileSection::Main])],
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\.]+\.conf$/'],
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
        ];
    }

    public function resolvedPath(): string
    {
        return $this->validated('section').'/'.$this->validated('name');
    }
}
