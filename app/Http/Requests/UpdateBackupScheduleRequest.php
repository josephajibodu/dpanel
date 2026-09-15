<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBackupScheduleRequest extends FormRequest
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
            'storage_provider_id' => [
                'required',
                Rule::exists('storage_providers', 'id')->where('team_id', $this->route('team')->id),
            ],
            'frequency' => ['required', 'string', Rule::in(['hourly', 'daily', 'weekly'])],
            'retention_count' => ['required', 'integer', 'min:1', 'max:365'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
