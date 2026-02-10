<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Customize the URL prefix, middleware stack, and guards for all monox
    | routes. This gives you control over route access and grouping.
    |
    */
    'routes' => [
        'prefix' => 'monox',
        'middleware' => ['web'],
        'guards' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Date and Time Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how dates and times are handled in the monox.
    | This includes format settings preferences.
    |
    */
    'datetime' => [
        // Format settings for different date displays
        'formats' => [
            'default' => 'Y-m-d H:i:s',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            'year_month' => 'Y-m',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models Configuration
    |--------------------------------------------------------------------------
    |
    | Define the model classes used by the monox.
    |
    */
    'models' => [
        'department' => \Lastdino\Monox\Models\Department::class,
        // ユーザー独自のモデルを使用する場合:
        // 'department' => \App\Models\Department::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables Configuration
    |--------------------------------------------------------------------------
    |
    | Define the table names used by the monox.
    |
    */
    'tables' => [
        'departments' => 'monox_departments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Configuration
    |--------------------------------------------------------------------------
    |
    | Configure settings related to production processing.
    |
    */
    'production' => [
        // Columns to search for worker when scanning worker code
        'worker_scan_columns' => ['id', 'email'],
    ],
];
