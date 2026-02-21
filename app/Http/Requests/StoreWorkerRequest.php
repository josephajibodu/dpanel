<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('server'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Server $server */
        $server = $this->route('server');

        return [
            'name' => ['required', 'string', 'max:255'],
            'command' => ['required', 'string', 'max:1024'],
            'site_id' => [
                'nullable',
                'integer',
                Rule::exists('sites', 'id')->where('server_id', $server->id),
            ],
            'user' => ['required', 'string', 'max:64'],
            'numprocs' => ['required', 'integer', 'min:1', 'max:64'],
            'auto_start' => ['boolean'],
            'auto_restart' => ['boolean'],
            'redirect_stderr' => ['nullable', 'boolean'],
            'stdout_logfile' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a worker name.',
            'command.required' => 'Please enter the command to run.',
            'site_id.exists' => 'The selected site does not belong to this server.',
            'user.required' => 'Please specify the user to run the worker as.',
        ];
    }
}
