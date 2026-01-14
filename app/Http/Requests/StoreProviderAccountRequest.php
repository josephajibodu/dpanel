<?php

namespace App\Http\Requests;

use App\Enums\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderAccountRequest extends FormRequest
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
            'provider' => ['required', 'string', Rule::enum(Provider::class)],
            'name' => ['required', 'string', 'max:255'],
            'api_token' => ['required', 'string', 'min:10'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Please select a cloud provider.',
            'name.required' => 'Please provide a name for this account.',
            'api_token.required' => 'Please enter your API token.',
            'api_token.min' => 'The API token seems too short. Please check and try again.',
        ];
    }
}
