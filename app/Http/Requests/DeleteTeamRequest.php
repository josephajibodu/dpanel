<?php

namespace App\Http\Requests;

use App\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a team cannot be undone, so the request must repeat the team's
 * exact name, proving the user meant this team and not another.
 */
class DeleteTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Team $team */
        $team = $this->route('team');

        return $this->user()->can('delete', $team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('team');

        return [
            'name' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($team): void {
                    if ($value !== $team->name) {
                        $fail('The name you typed does not match this team\'s name.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Type the team\'s name to confirm you want to delete it.',
        ];
    }
}
