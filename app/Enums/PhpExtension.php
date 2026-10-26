<?php

namespace App\Enums;

/**
 * Optional PHP extensions that can be installed or removed from the PHP page.
 *
 * None of these is a dependency of the extensions every server gets during
 * provisioning, so removing one never takes a built-in extension with it.
 */
enum PhpExtension: string
{
    case Apcu = 'apcu';
    case Bz2 = 'bz2';
    case Gmp = 'gmp';
    case Imagick = 'imagick';
    case Ldap = 'ldap';
    case Memcached = 'memcached';
    case Mongodb = 'mongodb';
    case Soap = 'soap';

    public function label(): string
    {
        return match ($this) {
            self::Apcu => 'APCu',
            self::Bz2 => 'Bzip2',
            self::Gmp => 'GMP',
            self::Imagick => 'Imagick',
            self::Ldap => 'LDAP',
            self::Memcached => 'Memcached',
            self::Mongodb => 'MongoDB',
            self::Soap => 'SOAP',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Apcu => 'In-memory cache for data shared between requests.',
            self::Bz2 => 'Read and write bzip2-compressed files.',
            self::Gmp => 'Arithmetic on very large numbers.',
            self::Imagick => 'Image processing with ImageMagick.',
            self::Ldap => 'Connect to LDAP and Active Directory servers.',
            self::Memcached => 'Client for Memcached cache servers.',
            self::Mongodb => 'Driver for MongoDB databases.',
            self::Soap => 'Call and serve SOAP web services.',
        };
    }

    /**
     * The apt package that provides this extension for a PHP version.
     */
    public function package(string $phpVersion): string
    {
        return "php{$phpVersion}-{$this->value}";
    }
}
