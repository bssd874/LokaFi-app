<?php

return [
    'env' => env('BRANKAS_ENV', 'sandbox'),
    'api_key' => env('BRANKAS_API_KEY'),
    'client_id' => env('BRANKAS_CLIENT_ID'),
    'client_secret' => env('BRANKAS_CLIENT_SECRET'),
    'base_url' => env('BRANKAS_BASE_URL'),
    'callback_url' => env('BRANKAS_CALLBACK_URL'),

    'paths' => [
        'supported_banks' => '/v1/statement-banks',
        'start_connection' => '/v1/statement-init',
        'statement' => '/v1/statements',
        'statement_retrieval' => '/v1/statement-retrieval',
    ],

    'providers' => [
        'bri' => [
            'code' => 'bri',
            'brankas_code' => 'BRI_PERSONAL',
            'name' => 'Bank Rakyat Indonesia',
            'country' => 'ID',
        ],
        'mandiri' => [
            'code' => 'mandiri',
            'brankas_code' => 'MANDIRI_PERSONAL',
            'name' => 'Bank Mandiri',
            'country' => 'ID',
        ],
        'bca' => [
            'code' => 'bca',
            'brankas_code' => 'BCA_PERSONAL',
            'name' => 'Bank Central Asia',
            'country' => 'ID',
        ],
    ],
];
