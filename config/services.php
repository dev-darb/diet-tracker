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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Open Food Facts (BUILD_PLAN D3, idea #2; brief §7.5). Keyless, but the API
    | REQUIRES a descriptive User-Agent identifying the app. Used as the
    | authoritative barcode → nutrition source before any LLM (§2.1).
    */
    'open_food_facts' => [
        'base_url' => env('OFF_BASE_URL', 'https://world.openfoodfacts.org'),
        'user_agent' => env('OFF_USER_AGENT', 'DietTracker/0.1 (alpha; contact via github dev-darb/diet-tracker)'),
        'timeout' => (int) env('OFF_TIMEOUT', 10),
    ],

];
