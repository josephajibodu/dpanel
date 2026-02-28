<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePhpSettingsRequest extends FormRequest
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
        return [
            'upload_max_filesize' => ['nullable', 'string', 'max:32'],
            'post_max_size' => ['nullable', 'string', 'max:32'],
            'max_execution_time' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'memory_limit' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_execution_time.min' => 'Max execution time must be at least 1 second.',
            'max_execution_time.max' => 'Max execution time may not exceed 86400 seconds.',
        ];
    }
}
