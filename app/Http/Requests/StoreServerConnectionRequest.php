<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('server'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Server $server */
        $server = $this->route('server');

        return [
            'client_server_id' => [
                'required',
                'integer',
                Rule::notIn([$server->id]),
                Rule::exists('servers', 'id')->where('team_id', $server->team_id),
                Rule::unique('server_connections', 'client_server_id')->where('server_id', $server->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_server_id.required' => 'Choose a server to connect.',
            'client_server_id.not_in' => 'A server can already reach its own services.',
            'client_server_id.exists' => 'Choose one of your team\'s servers.',
            'client_server_id.unique' => 'That server is already connected.',
        ];
    }
}
