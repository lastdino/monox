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
            'short_date' => 'm/d',
            'datetime' => 'Y/m/d H:i',
            'short_datetime' => 'm/d H:i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how data is displayed in the UI.
    |
    */
    'display' => [
        // Column name of the worker/user to display in the UI (e.g., 'name', 'email')
        'worker_column' => 'name',
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
        'department' => "\\Lastdino\\Monox\\Models\\Department",
        // ユーザー独自のモデルを使用する場合:
        // 'department' => "\\App\\Models\\Department",
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

        // Query parameter name used for process selection in worksheet
        'worksheet_process_parameter' => 'process',

        // QR code settings for travel sheet
        'travel_sheet' => [
            'qr_sizes' => [
                'order_url' => 60,
                'lot_number' => 50,
                'process_url' => 45,
            ],
        ],
    ],
];
