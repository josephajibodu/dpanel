<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Server User
    |--------------------------------------------------------------------------
    |
    | The default user created on provisioned servers. This user is used for
    | SSH connections, file ownership, and application deployment.
    |
    */

    'user' => env('SERVER_USER', 'artisan'),

    /*
    |--------------------------------------------------------------------------
    | Web Server User
    |--------------------------------------------------------------------------
    |
    | The user that PHP-FPM runs as for web requests. This user needs write
    | access to storage and bootstrap/cache so Laravel can compile views,
    | write logs, and manage sessions.
    |
    */

    'web_user' => env('SERVER_WEB_USER', 'www-data'),

    /*
    |--------------------------------------------------------------------------
    | Supported Ubuntu Versions
    |--------------------------------------------------------------------------
    |
    | Provisioning only supports these Ubuntu VERSION_ID values (as reported by
    | /etc/os-release). StackInstaller checks this before doing any real work
    | so an incompatible server fails fast with a clear message instead of
    | partway through an opaque apt-get error.
    |
    */

    'supported_ubuntu_versions' => ['22.04', '24.04', '26.04'],

    /*
    |--------------------------------------------------------------------------
    | Server Home Directory
    |--------------------------------------------------------------------------
    |
    | The home directory for the server user. Sites are typically stored in
    | subdirectories of this path.
    |
    */

    'home_directory' => '/home/artisan',

    /*
    |--------------------------------------------------------------------------
    | Free Domain
    |--------------------------------------------------------------------------
    |
    | The base domain used for auto-generated site subdomains. When a user
    | creates a site with a site_name instead of a custom domain, the site
    | will be accessible at {site_name}.{free_domain}.
    |
    */

    'free_domain' => env('FREE_DOMAIN', 'flitops.xyz'),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare DNS
    |--------------------------------------------------------------------------
    |
    | Credentials for the Cloudflare API used to manage DNS records for
    | the free domain. An A record is created when a site uses the free
    | domain and removed when the site is deleted.
    |
    */

    'cloudflare_api_token' => env('CLOUDFLARE_API_TOKEN'),

    'cloudflare_zone_id' => env('CLOUDFLARE_ZONE_ID'),

    /*
    |--------------------------------------------------------------------------
    | acme.sh binary
    |--------------------------------------------------------------------------
    |
    | Path to the acme.sh executable on the FlitOps host (the machine running
    | the queue worker). WildcardCertificateIssuer shells out to this binary
    | to issue and renew the free-domain wildcard certificate via the
    | Cloudflare DNS-01 challenge. Leave unset to look up "acme.sh" on PATH.
    |
    */

    'acme_binary' => env('ACME_BINARY', 'acme.sh'),

];
