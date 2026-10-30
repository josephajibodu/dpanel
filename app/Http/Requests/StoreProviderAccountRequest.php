<?php

namespace App\Http\Requests;

use App\Enums\Provider;
use App\Models\ProviderAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [ProviderAccount::class, $this->route('team')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isAws = $this->input('provider') === Provider::Aws->value;

        return [
            'provider' => ['required', 'string', Rule::enum(Provider::class)],
            'name' => ['required', 'string', 'max:255'],
            'api_token' => [Rule::requiredIf(! $isAws), 'nullable', 'string', 'min:10'],
            'access_key_id' => [Rule::requiredIf($isAws), 'nullable', 'string', 'min:16'],
            'secret_access_key' => [Rule::requiredIf($isAws), 'nullable', 'string', 'min:20'],
        ];
    }

    /**
     * Get the credential fields relevant to the selected provider, ready to
     * store on the model.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        $provider = Provider::from($this->validated('provider'));

        return collect($provider->credentialFields())
            ->mapWithKeys(fn (string $field) => [$field => $this->validated($field)])
            ->all();
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
            'access_key_id.required' => 'Please enter your access key ID.',
            'secret_access_key.required' => 'Please enter your secret access key.',
        ];
    }
}
