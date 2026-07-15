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

    'ai' => [
        'enabled' => env('AI_ENABLED', false),
        'provider' => env('AI_PROVIDER'),
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_API_BASE_URL'),
        'model' => env('AI_MODEL'),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
        'financial_insights' => [
            'enabled' => env('AI_FINANCIAL_INSIGHTS_ENABLED', false),
            'model' => env('AI_FINANCIAL_INSIGHT_MODEL'),
            'prompt_version' => env('AI_FINANCIAL_INSIGHT_PROMPT_VERSION', 'financial_insight_id_v2'),
            'timeout_seconds' => (int) env('AI_FINANCIAL_INSIGHT_TIMEOUT_SECONDS', 30),
            'cache_hours' => (int) env('AI_FINANCIAL_INSIGHT_CACHE_HOURS', 24),
        ],
    ],

];
