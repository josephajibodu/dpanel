<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSshKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $publicKey = $this->input('public_key');

            if (! $publicKey) {
                return;
            }

            // Validate SSH key format
            if (! $this->isValidSshKey($publicKey)) {
                $validator->errors()->add(
                    'public_key',
                    'The public key must be a valid SSH key (ssh-rsa, ssh-ed25519, ecdsa-sha2-nistp256, or ecdsa-sha2-nistp384).'
                );

                return;
            }

            // Check for uniqueness
            $fingerprint = $this->calculateFingerprint($publicKey);
            $exists = auth()->user()->sshKeys()->where('fingerprint', $fingerprint)->exists();

            if ($exists) {
                $validator->errors()->add(
                    'public_key',
                    'This SSH key has already been added to your account.'
                );
            }
        });
    }

    /**
     * Validate that the public key is a valid SSH key format.
     */
    public function isValidSshKey(string $key): bool
    {
        $validPrefixes = [
            'ssh-rsa',
            'ssh-ed25519',
            'ecdsa-sha2-nistp256',
            'ecdsa-sha2-nistp384',
            'ecdsa-sha2-nistp521',
            'ssh-dss',
        ];

        $key = trim($key);

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($key, $prefix.' ')) {
                // Try to decode the base64 portion
                $parts = explode(' ', $key);
                if (count($parts) >= 2) {
                    $decoded = base64_decode($parts[1], true);

                    return $decoded !== false && strlen($decoded) > 0;
                }
            }
        }

        return false;
    }

    /**
     * Calculate the MD5 fingerprint of an SSH public key.
     */
    public function calculateFingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));

        if (count($parts) < 2) {
            return '';
        }

        $keyData = base64_decode($parts[1], true);

        if ($keyData === false) {
            return '';
        }

        return implode(':', str_split(md5($keyData), 2));
    }
}
