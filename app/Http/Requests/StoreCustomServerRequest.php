<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
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
            'ip_address' => ['required', 'string', 'max:255'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'php_version' => ['sometimes', 'string', Rule::in(['8.1', '8.2', '8.3', '8.4'])],
            'database_type' => ['sometimes', 'string', Rule::in(['mysql', 'postgresql', 'mariadb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please provide a server name.',
            'name.regex' => 'Server name can only contain letters, numbers, and hyphens.',
            'ip_address.required' => 'Please provide the server IP address or hostname.',
            'ssh_port.required' => 'Please provide the SSH port.',
        ];
    }
}
