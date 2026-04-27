<?php

namespace App\Http\Requests;

use App\Enums\WwwRedirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteDomainRequest extends FormRequest
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
            'hostname' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/',
            ],
            'wildcard_enabled' => ['required', 'boolean'],
            'www_redirect' => ['required', 'string', Rule::enum(WwwRedirect::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hostname.regex' => 'Please enter a valid domain name (e.g., example.com).',
        ];
    }
}
