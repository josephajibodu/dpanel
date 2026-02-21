<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerDatabaseRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('server_databases', 'name')->where('server_id', $server->id),
            ],
            'collation' => ['nullable', 'string', 'max:64'],
            'charset' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a database name.',
            'name.regex' => 'Database name can only contain letters, numbers, and underscores.',
            'name.unique' => 'A database with this name already exists on this server.',
        ];
    }
}
