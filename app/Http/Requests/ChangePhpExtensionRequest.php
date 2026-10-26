<?php

namespace App\Http\Requests;

use App\Enums\PhpExtension;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePhpExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePhp', $this->route('server'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string'],
            'extension' => ['required', 'string', Rule::enum(PhpExtension::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extension.required' => 'Please select an extension.',
            'extension.enum' => 'The selected extension cannot be managed here.',
        ];
    }
}
