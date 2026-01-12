<?php

return [
    // Active billing provider / driver
    'default' => env('BILLING_PROVIDER', env('BILLING_DRIVER', 'interswitch')),

    'providers' => [

        'interswitch' => [
            // Quickteller Bills v5 endpoints
            'login_url' => env('INTERSWITCH_LOGIN_URL', 'https://qa.interswitchng.com'),
            'base_url' => env('INTERSWITCH_BASE_URL', 'https://qa.interswitchng.com/quicktellerservice/api/v5'),

            // OAuth client credentials
            'client_id' => env('INTERSWITCH_CLIENT_ID'),
            'client_secret' => env('INTERSWITCH_CLIENT_SECRET'),

            // Terminal / merchant details
            'terminal_id' => env('INTERSWITCH_TERMINAL_ID'),

            // HTTP client
            'timeout' => env('INTERSWITCH_BILLING_TIMEOUT', 30),

            // Signature configuration
            'signature_method' => 'SHA256',

            // Endpoint paths (relative to base_url)
            'endpoints' => [
                'purchase' => '/Transactions',
                'transaction_status' => '/Transactions', // GET with requestRef query
                'categories' => '/services/categories',
                'services' => '/services',
                'services_by_category' => '/services', // with categoryId
                'service_options' => '/services/options',
                'validate_customer' => '/Transactions/validatecustomers',
            ],
        ],

        'flutterwave' => [
            'base_url' => env('FLUTTERWAVE_BILLS_BASE_URL', 'https://api.flutterwave.com/v3'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'timeout' => env('FLUTTERWAVE_BILLS_TIMEOUT', 30),
        ],

        'paystack_bills' => [
            'base_url' => env('PAYSTACK_BILLS_BASE_URL', 'https://api.paystack.co'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'timeout' => env('PAYSTACK_BILLS_TIMEOUT', 30),
        ],
    ],
];
