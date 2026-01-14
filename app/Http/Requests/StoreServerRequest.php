<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9-]+$/',
            ],
            'provider_account_id' => [
                'required',
                'integer',
                Rule::exists('provider_accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'region' => ['required', 'string', 'max:50'],
            'size' => ['required', 'string', 'max:50'],
            'php_version' => ['sometimes', 'string', Rule::in(['8.1', '8.2', '8.3', '8.4'])],
            'database_type' => ['sometimes', 'string', Rule::in(['mysql', 'postgresql', 'mariadb'])],
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
            'name.required' => 'Please provide a server name.',
            'name.regex' => 'Server name can only contain letters, numbers, and hyphens.',
            'provider_account_id.required' => 'Please select a provider account.',
            'provider_account_id.exists' => 'The selected provider account is invalid.',
            'region.required' => 'Please select a region.',
            'size.required' => 'Please select a server size.',
        ];
    }
}
