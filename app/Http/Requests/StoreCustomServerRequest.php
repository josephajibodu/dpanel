<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServerServices;
use App\Models\Server;
use App\Rules\PublicIpAddress;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomServerRequest extends FormRequest
{
    use ValidatesServerServices;

    public function authorize(): bool
    {
        return $this->user()->can('create', [Server::class, $this->route('team')]);
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
            'ip_address' => ['required', 'string', 'max:255', new PublicIpAddress],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            ...$this->serviceRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->serviceMessages(),
            'name.required' => 'Please provide a server name.',
            'name.regex' => 'Server name can only contain letters, numbers, and hyphens.',
            'ip_address.required' => 'Please provide the server\'s public IP address.',
            'ssh_port.required' => 'Please provide the SSH port.',
        ];
    }
}
