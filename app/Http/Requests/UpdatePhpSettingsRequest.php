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
            'upload_max_filesize' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/iD'],
            'post_max_size' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/iD'],
            'max_execution_time' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'memory_limit' => ['nullable', 'string', 'max:32', 'regex:/^(-1|\d+[KMG]?)$/iD'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'upload_max_filesize.regex' => 'Upload max filesize must be a number, optionally followed by K, M or G (for example 64M).',
            'post_max_size.regex' => 'Post max size must be a number, optionally followed by K, M or G (for example 64M).',
            'memory_limit.regex' => 'Memory limit must be a number, optionally followed by K, M or G (for example 256M), or -1 for no limit.',
            'max_execution_time.min' => 'Max execution time must be at least 1 second.',
            'max_execution_time.max' => 'Max execution time may not exceed 86400 seconds.',
        ];
    }
}
