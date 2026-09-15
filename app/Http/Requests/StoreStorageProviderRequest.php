<?php

namespace App\Http\Requests;

use App\Enums\StorageProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorageProviderRequest extends FormRequest
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
        $isR2 = $this->input('type') === StorageProviderType::CloudflareR2->value;
        $isS3 = $this->input('type') === StorageProviderType::S3->value;

        return [
            'type' => ['required', 'string', Rule::enum(StorageProviderType::class)],
            'name' => ['required', 'string', 'max:255'],
            'access_key_id' => ['required', 'string', 'min:5'],
            'secret_access_key' => ['required', 'string', 'min:5'],
            'bucket' => ['required', 'string', 'max:255'],
            'account_id' => [Rule::requiredIf($isR2), 'nullable', 'string'],
            'region' => [Rule::requiredIf($isS3), 'nullable', 'string'],
        ];
    }

    /**
     * Get the credential fields relevant to the selected provider type, ready
     * to store on the model.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        $type = StorageProviderType::from($this->validated('type'));

        return collect($type->credentialFields())
            ->mapWithKeys(fn (string $field) => [$field => $this->validated($field)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Please select a storage provider.',
            'name.required' => 'Please provide a name for this account.',
            'access_key_id.required' => 'Please enter your access key ID.',
            'secret_access_key.required' => 'Please enter your secret access key.',
            'bucket.required' => 'Please enter your bucket name.',
            'account_id.required' => 'Please enter your Cloudflare account ID.',
            'region.required' => 'Please enter your bucket region.',
        ];
    }
}
