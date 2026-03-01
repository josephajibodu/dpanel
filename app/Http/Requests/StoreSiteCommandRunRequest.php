<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteCommandRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('site'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'command' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'command.required' => 'Please enter a command to run.',
        ];
    }
}
