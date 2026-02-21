<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('server'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Server $server */
        $server = $this->route('server');

        $validDatabaseNames = $server->databases()->pluck('name')->all();

        return [
            'username' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('database_users', 'username')->where('server_id', $server->id),
            ],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'databases' => ['required', 'array', 'min:1'],
            'databases.*' => ['string', 'max:64', Rule::in($validDatabaseNames)],
            'permission' => ['nullable', 'string', 'max:50', Rule::in(['readonly', 'readwrite'])],
            'host' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Please enter a username.',
            'username.regex' => 'Username can only contain letters, numbers, and underscores.',
            'username.unique' => 'A database user with this username already exists on this server.',
            'password.required' => 'Please enter a password.',
            'databases.required' => 'Please select at least one database.',
            'databases.*.in' => 'One or more selected databases are invalid.',
        ];
    }
}
