<?php

namespace App\Http\Requests;

use App\Enums\NodeVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNodeVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageRuntime', $this->route('server'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', Rule::enum(NodeVersion::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Please select a Node.js version.',
            'version.enum' => 'The selected Node.js version is not available.',
        ];
    }
}
