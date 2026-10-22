<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSshKeyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. Putting a key
     * on a server's own user (root access) needs admin server access; an
     * isolated site's user only needs server access.
     */
    public function authorize(): bool
    {
        $arguments = [$this->route('sshKey'), $this->route('team')];

        if ($this->routeIs('ssh-keys.revoke')) {
            return $this->user()->can('revoke', $arguments);
        }

        if (filled($this->input('server_ids'))) {
            return $this->user()->can('syncToServerUser', $arguments);
        }

        return $this->user()->can('sync', $arguments);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'server_ids' => ['nullable', 'required_without:site_ids', 'array'],
            'server_ids.*' => ['required', 'integer', 'exists:servers,id'],
            'site_ids' => ['nullable', 'required_without:server_ids', 'array'],
            'site_ids.*' => ['required', 'integer', 'exists:sites,id'],
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
            'server_ids.required_without' => 'Please select at least one server or site.',
            'site_ids.required_without' => 'Please select at least one server or site.',
        ];
    }
}
