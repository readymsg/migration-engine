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
    'firecrawl' => [
        'api_key' => env('FIRECRAWL_API_KEY'),
        'url' => env('FIRECRAWL_URL', 'https://api.firecrawl.dev/v2'),
    ],

    // Filesystem disk (from config/filesystems.php) the S3AssetUploader
    // writes scrapes, brand assets, and re-hosted SE-CDN assets to. Defaults
    // to 's3' for prod; set SCRAPES_DISK=local in .env to route to local
    // storage during dev so captured content can be read back without a
    // bucket. The class name is historical — any configured disk works.
    'scrapes' => [
        'disk' => env('SCRAPES_DISK', 's3'),
    ],

];
