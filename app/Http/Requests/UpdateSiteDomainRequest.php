<?php

namespace App\Http\Requests;

use App\Enums\WwwRedirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wildcard_enabled' => ['sometimes', 'boolean'],
            'www_redirect' => ['sometimes', 'string', Rule::enum(WwwRedirect::class)],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
