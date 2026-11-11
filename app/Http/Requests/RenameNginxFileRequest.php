<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RenameNginxFileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\.]+\.conf$/'],
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
}
