<?php

namespace App\Http\Requests;

use App\Enums\TeamRoleKey;
use App\Models\Team;
use App\Models\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteTeamMemberRequest extends FormRequest
{
    /**
     * Before validation runs the role may be missing or unknown; then this
     * only checks that the user may invite anyone, and validation rejects it.
     */
    public function authorize(): bool
    {
        /** @var Team $team */
        $team = $this->route('team');

        $requestedRole = is_string($this->input('role')) ? TeamRoleKey::tryFrom($this->input('role')) : null;

        return $this->user()->can('inviteMember', [$team, $requestedRole ? TeamRole::builtIn($requestedRole) : null]);
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
            'role' => ['required', 'string', Rule::enum(TeamRoleKey::class)],
        ];
    }

    public function teamRole(): TeamRole
    {
        return TeamRole::builtIn(TeamRoleKey::from($this->validated('role')));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'This person has already been invited.',
            'email.not_in' => 'This person is already a team member.',
            'role.required' => 'Choose a role for this person.',
            'role.enum' => 'Choose one of the listed roles.',
        ];
    }
}
