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

    // Carrier and import credentials live on the CarrierAccount / DataSource models,
    // not here. Only non-credential infrastructure (API base URLs, default API
    // version) remains in config.
    'usps' => [
        'base_url' => 'https://apis.usps.com',
        'sandbox_url' => 'https://apis-tem.usps.com',
    ],

    'fedex' => [
        'base_url' => 'https://apis.fedex.com',
        'sandbox_url' => 'https://apis-sandbox.fedex.com',
        'document_base_url' => 'https://documentapi.prod.fedex.com',
        'document_sandbox_url' => 'https://documentapitest.prod.fedex.com/sandbox',
    ],

    'ups' => [
        'base_url' => 'https://onlinetools.ups.com',
        'sandbox_url' => 'https://wwwcie.ups.com',
    ],

    'shopify' => [
        'api_version' => '2026-07',
    ],

    'oauth' => [
        'broker_url' => env('OAUTH_BROKER_URL'),
        'broker_secret' => env('OAUTH_BROKER_SECRET'),
        'instance_id' => env('OAUTH_INSTANCE_ID'),
        'bypass_broker' => env('OAUTH_BYPASS_BROKER', false),
    ],

    'amazon' => [
        'base_url' => 'https://sellingpartnerapi-na.amazon.com',
        // Intentionally a different region than base_url above: the only working
        // Orders API v2026-01-01 sandbox test case uses Amazon's JP marketplace ID
        // (A1VC38T7YXB528, see AmazonSource::fetchShipments()), which only resolves
        // against the FE sandbox host — the NA sandbox host 403s for it.
        'sandbox_url' => 'https://sandbox.sellingpartnerapi-fe.amazon.com',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

    // Separate from the 'google' SSO block above — this is a PolyBag-owned
    // API key for the Address Validation API, only used as a direct-call
    // fallback in local dev when polybag-connect isn't running.
    'google_address_validation' => [
        'api_key' => env('GOOGLE_ADDRESS_VALIDATION_API_KEY'),
        'base_url' => 'https://addressvalidation.googleapis.com',
    ],

    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'tenant' => env('AZURE_TENANT_ID', 'common'),
        'redirect' => '/auth/azure/callback',
        'proxy' => null,
    ],

    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
    ],

];
