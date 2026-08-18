<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'interserver' => [
        'url' => env('INTERSERVER_API_URL', 'https://my.interserver.net/apiv2'),
        'key' => env('INTERSERVER_API_KEY'),
        'profit_usd' => env('PROFIT_USD', 1),
    ],

    'exchange_rate' => [
        'key' => env('EXCHANGERATE_API_KEY'),
    ],

    'fossbilling' => [
        'url' => env('FOSSBILLING_URL'),
        'login_url' => env('FOSSBILLING_LOGIN_URL'),
        'order_url' => env('FOSSBILLING_ORDER_URL'),
        'domain_order_url' => env('FOSSBILLING_DOMAIN_ORDER_URL'),
        'admin_api_key' => env('FOSSBILLING_ADMIN_API_KEY'),
        // Seven-day starter trial wizard served by the Quizontalfreetrial
        // FOSSBilling module. Blank hides the storefront call to action.
        'free_trial_url' => env('FOSSBILLING_FREE_TRIAL_URL'),
    ],

    'porkbun' => [
        'api_url' => env('PORKBUN_API_URL', 'https://api.porkbun.com/api/json/v3'),
        // Flat USD margin added to each domain year price before LKR conversion.
        'profit_usd' => env('DOMAIN_PROFIT_USD', 1),
        // Comma-separated TLDs to sync (`com,net,org`), `*` for everything,
        // empty for the built-in popular set.
        'sync_tlds' => env('DOMAIN_SYNC_TLDS', ''),
    ],
];
