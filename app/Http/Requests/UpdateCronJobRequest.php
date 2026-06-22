<?php

namespace App\Http\Requests;

use App\Models\Server;
use App\Rules\ValidCronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCronJobRequest extends FormRequest
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
            'command' => ['required', 'string', 'max:1024'],
            'site_id' => [
                'nullable',
                'integer',
                Rule::exists('sites', 'id')->where('server_id', $server->id),
            ],
            'user' => ['required', 'string', 'max:64'],
            'frequency' => ['required', 'string', 'max:128', new ValidCronExpression],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'command.required' => 'Please enter the command to run.',
            'site_id.exists' => 'The selected site does not belong to this server.',
            'user.required' => 'Please specify the user to run the cron job as.',
            'frequency.required' => 'Please select a frequency.',
        ];
    }
}
