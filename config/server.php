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
    | Server Home Directory
    |--------------------------------------------------------------------------
    |
    | The home directory for the server user. Sites are typically stored in
    | subdirectories of this path.
    |
    */

    'home_directory' => '/home/artisan',

];
