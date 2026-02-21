<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cron.d directory
    |--------------------------------------------------------------------------
    |
    | Directory on the server for cron.d-style files (one file per cron job).
    |
    */

    'cron_d_path' => env('CRON_D_PATH', '/etc/cron.d'),

    /*
    |--------------------------------------------------------------------------
    | Cron file prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for generated cron.d filenames (e.g. flitops-cron-123).
    |
    */

    'file_prefix' => env('CRON_FILE_PREFIX', 'flitops-cron'),

];
