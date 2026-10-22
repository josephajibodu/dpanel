<?php

namespace App\Http\Requests;

use App\Enums\TeamRoleKey;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRoleRequest extends FormRequest
{
    /**
     * Before validation runs the role may be missing or unknown; then this
     * only checks that the user may change this member at all, and
     * validation rejects the role.
     */
    public function authorize(): bool
    {
        /** @var Team $team */
        $team = $this->route('team');

        /** @var User $member */
        $member = $this->route('user');

        $requestedRole = is_string($this->input('role')) ? TeamRoleKey::tryFrom($this->input('role')) : null;

        return $this->user()->can('changeRole', [$team, $member, $requestedRole ? TeamRole::builtIn($requestedRole) : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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
            'role.required' => 'Choose a role for this member.',
            'role.enum' => 'Choose one of the listed roles.',
        ];
    }
}
