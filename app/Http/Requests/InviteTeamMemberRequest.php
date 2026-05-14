<?php

namespace App\Http\Requests;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Team $team */
        $team = $this->route('team');

        return $this->user()->ownsTeam($team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('team');

        return [
            'email' => [
                'required',
                'email',
                Rule::unique('team_invitations', 'email')->where('team_id', $team->id),
                Rule::notIn($team->users()->pluck('email')->all()),
            ],
            'role' => ['required', 'string', Rule::in(['owner', 'member'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'This person has already been invited.',
            'email.not_in' => 'This person is already a team member.',
        ];
    }
}
