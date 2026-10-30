<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServerServices;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerRequest extends FormRequest
{
    use ValidatesServerServices;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Server::class, $this->route('team')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
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
                Rule::exists('provider_accounts', 'id')->where('team_id', $this->route('team')->id),
            ],
            'region' => ['required', 'string', 'max:50'],
            'size' => ['required', 'string', 'max:50'],
            ...$this->serviceRules(),
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
            ...$this->serviceMessages(),
            'name.required' => 'Please provide a server name.',
            'name.regex' => 'Server name can only contain letters, numbers, and hyphens.',
            'provider_account_id.required' => 'Please select a provider account.',
            'provider_account_id.exists' => 'The selected provider account is invalid.',
            'region.required' => 'Please select a region.',
            'size.required' => 'Please select a server size.',
        ];
    }
}
