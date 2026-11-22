<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | Virtual tour platform providers
    |--------------------------------------------------------------------------
    |
    | Secrets stay server-side. Features whose provider is not configured are
    | disabled in the UI (see App\Support\ProviderConfiguration).
    |
    */

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_UPLOAD_FOLDER', 'virtualtour'),
    ],

    // Models, pricing and limits live in config/ai.php.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'skybox' => [
        'api_key' => env('SKYBOX_API_KEY'),
        'webhook_secret' => env('SKYBOX_WEBHOOK_SECRET'),
    ],

    'fal' => [
        'key' => env('FAL_KEY'),
    ],

    // Google OAuth client, used to publish tours to Street View.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'min_panorama_width' => 3840,
    ],

    // Cloudflare for SaaS: certificates and routing for customers' custom domains.
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'origin_hostname' => env('CLOUDFLARE_ORIGIN_HOSTNAME'),
        // Paid SSL for SaaS only. Leave off on the Free plan (error 1456).
        'custom_origin' => (bool) env('CLOUDFLARE_CUSTOM_ORIGIN', false),
    ],

    'ffmpeg' => [
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'temp_path' => env('VIDEO_TEMP_PATH'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
