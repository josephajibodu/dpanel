<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supervisor config directory
    |--------------------------------------------------------------------------
    |
    | Directory on the server where worker config files are placed (e.g. conf.d).
    |
    */

    'conf_path' => env('SUPERVISOR_CONF_PATH', '/etc/supervisor/conf.d'),

    /*
    |--------------------------------------------------------------------------
    | Config file prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for generated config filenames (e.g. flitops-worker-123.conf).
    |
    */

    'file_prefix' => env('SUPERVISOR_FILE_PREFIX', 'flitops-worker'),

];
