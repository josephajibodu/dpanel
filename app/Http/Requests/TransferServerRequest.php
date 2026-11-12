<?php

namespace App\Http\Requests;

use App\Enums\Provider;
use App\Enums\ServerStatus;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\Team;
use App\Services\Providers\ProviderManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class TransferServerRequest extends FormRequest
{
    /**
     * Stop at the first field that fails, so the cloud provider is only
     * asked about the server once the team itself is acceptable.
     *
     * @var bool
     */
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return $this->user()->can('transfer', $this->route('server'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Server $server */
        $server = $this->route('server');

        return [
            'team' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($server): void {
                    $destination = $this->destination();

                    if ($destination === null
                        || $destination->id === $server->team_id
                        || $this->user()->cannot('create', [Server::class, $destination])) {
                        $fail('Choose another team you can manage servers in.');

                        return;
                    }

                    if ($destination->user_id !== $server->team->user_id && ! $destination->hasRoomForAnotherServer()) {
                        $fail("{$destination->name} can't take another server. {$destination->plan()->serverLimitMessage()}");

                        return;
                    }

                    if ($server->status !== ServerStatus::Active) {
                        $fail('Only an active server can be transferred.');

                        return;
                    }

                    if ($server->hostedConnections()->exists() || $server->clientConnections()->exists()) {
                        $fail('Remove this server\'s connections to other servers first.');
                    }
                },
            ],
            'provider_account' => [
                Rule::requiredIf($server->provider !== Provider::Custom),
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($server): void {
                    $account = $this->providerAccount();

                    if ($account === null) {
                        $fail("Choose one of that team's {$server->provider->label()} accounts.");

                        return;
                    }

                    if (! $this->accountCanSeeServer($account, $server)) {
                        $fail("That account can't find this server at {$server->provider->label()}. Choose the account the server was created with.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'team.required' => 'Choose a team to transfer this server to.',
            'provider_account.required' => 'Choose the cloud account this server will belong to in the new team.',
        ];
    }

    /**
     * The team the server is going to, or null when there is no such team.
     */
    public function destination(): ?Team
    {
        return Team::query()->where('slug', $this->string('team')->toString())->first();
    }

    /**
     * The destination team's cloud account the server will be linked to.
     * Always null for a custom server, which has no cloud account.
     */
    public function providerAccount(): ?ProviderAccount
    {
        /** @var Server $server */
        $server = $this->route('server');

        if ($server->provider === Provider::Custom) {
            return null;
        }

        return $this->destination()?->providerAccounts()
            ->where('ulid', $this->string('provider_account')->toString())
            ->where('provider', $server->provider)
            ->where('is_valid', true)
            ->first();
    }

    /**
     * Whether the account's credentials reach the machine itself. FlitOps
     * later destroys the machine through this account, so it must be the
     * cloud account the machine lives in.
     */
    private function accountCanSeeServer(ProviderAccount $account, Server $server): bool
    {
        if ($server->provider_server_id === null) {
            return false;
        }

        try {
            app(ProviderManager::class)->forAccount($account)->getServerStatus($server->provider_server_id);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
