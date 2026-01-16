<?php

namespace App\Enums;

enum RepositoryProvider: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::Gitlab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Custom => 'Custom Git',
        };
    }

    public function baseUrl(): ?string
    {
        return match ($this) {
            self::Github => 'https://github.com',
            self::Gitlab => 'https://gitlab.com',
            self::Bitbucket => 'https://bitbucket.org',
            self::Custom => null,
        };
    }
}
