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

    /*
    |--------------------------------------------------------------------------
    | Worker log directory
    |--------------------------------------------------------------------------
    |
    | Where each worker's output is written (e.g. flitops-worker-123.log).
    | supervisord opens these files as root, so this must be a directory only
    | root can write to. Otherwise the server user could swap a log file for
    | a link to a system file and have root write into it.
    |
    */

    'log_path' => env('SUPERVISOR_LOG_PATH', '/var/log/supervisor'),

];
