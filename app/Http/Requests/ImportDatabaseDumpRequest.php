<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportDatabaseDumpRequest extends FormRequest
{
    /**
     * Importing overwrites the database's data, just like a restore does.
     */
    public function authorize(): bool
    {
        return $this->user()->can('restoreBackups', $this->route('server'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'storage_provider_id' => [
                'required',
                Rule::exists('storage_providers', 'id')
                    ->where('team_id', $this->route('team')->id)
                    ->where('is_valid', true),
            ],
            'dump' => ['required', 'file', 'extensions:sql,gz', 'max:1048576'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dump.extensions' => 'The dump must be a .sql or .sql.gz file.',
            'dump.max' => 'The dump can be at most 1 GB.',
            'dump.uploaded' => 'The dump couldn\'t be uploaded. It may be larger than this server allows.',
        ];
    }
}
