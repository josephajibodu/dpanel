<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNginxFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('editWebServerConfig', $this->route('site'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:65535'],
        ];
    }
}
