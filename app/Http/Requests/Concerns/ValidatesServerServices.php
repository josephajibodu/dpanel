<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ServerType;
use Illuminate\Validation\Rule;

/**
 * Shared rules for choosing a server's type and services. A type is a preset:
 * any service the request leaves out is filled in from the type's defaults,
 * and an explicit null means "don't install it".
 */
trait ValidatesServerServices
{
    protected function prepareForValidation(): void
    {
        $type = ServerType::tryFrom((string) $this->input('type', ServerType::App->value));

        if ($type === null) {
            return;
        }

        $preset = $type->defaultServices();

        $defaults = [
            'php_version' => $preset['php'] ? '8.5' : null,
            'web_server' => $preset['web_server'] ? 'nginx' : null,
            'database_type' => $preset['database'] ? 'mysql' : null,
            'redis' => $preset['redis'],
        ];

        $this->merge([
            'type' => $type->value,
            ...array_diff_key($defaults, $this->all()),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function serviceRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_map(fn (ServerType $type) => $type->value, ServerType::selectable()))],
            'php_version' => ['nullable', 'string', Rule::in(['8.1', '8.2', '8.3', '8.4', '8.5'])],
            'web_server' => ['nullable', 'string', Rule::in(['nginx', 'caddy']), Rule::prohibitedIf(fn (): bool => $this->input('php_version') === null)],
            'database_type' => ['nullable', 'string', Rule::in(['mysql', 'postgresql', 'mariadb'])],
            'redis' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function serviceMessages(): array
    {
        return [
            'type.in' => 'Please choose an available server type.',
            'web_server.prohibited' => 'A web server needs PHP. Choose a PHP version or turn off the web server.',
        ];
    }
}
