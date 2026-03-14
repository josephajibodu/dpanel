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
    | Server Home Directory
    |--------------------------------------------------------------------------
    |
    | The home directory for the server user. Sites are typically stored in
    | subdirectories of this path.
    |
    */

    'home_directory' => '/home/artisan',

];
