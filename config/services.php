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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    // Google OAuth credentials for Socialite.
    // Note: The 'redirect' URI is intentionally left unused here. Multiple controllers
    // use Socialite with different callback paths (web login uses /auth/google/callback,
    // the athlete sync API uses /api/sync/auth/google/callback). Each controller passes
    // ->redirectUrl(url('/path/to/callback')) explicitly so that a single set of
    // credentials can serve both flows without needing a per-route env variable.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => null,
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
    ],

    'athlete' => [
        'url' => env('ATHLETE_APP_URL', 'https://flagship.squirby.ai'),

        // Allowlist of hosts the OAuth callback is permitted to redirect back to.
        // A state-supplied redirect URL is only honored if its host appears here
        // (the configured 'url' host above is always allowed); otherwise the
        // configured 'url' is used. This prevents an attacker from crafting the
        // (unsigned) OAuth state to exfiltrate the issued token to their own host.
        // Defaults cover the known athlete origins; override via env (comma-separated).
        'allowed_redirect_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATHLETE_ALLOWED_REDIRECT_HOSTS', 'squirby.app,flagship.squirby.ai,localhost'))
        ))),
    ],

];
