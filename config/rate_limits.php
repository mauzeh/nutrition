<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the application's named rate limiters. The
    | limiters themselves are registered in App\Providers\AppServiceProvider
    | from these values; keep thresholds here rather than hardcoded in code.
    |
    | For each limiter, `per_minute` is the burst cap and `per_hour` (optional)
    | is a looser sustained cap that catches slow, paced abuse staying under
    | the per-minute limit. A `null` window is not enforced.
    |
    */

    // Web + Sync registration. Keyed per IP. Slows mass account creation.
    'register' => [
        'per_minute' => 2,
        'per_hour' => 5,
    ],

    // Web + Sync login. Keyed per email + IP so a shared NAT IP or a single
    // struggling user is not locked out by unrelated traffic, while an attacker
    // spreading across many emails is still caught per email.
    'login' => [
        'per_minute' => 2,
        'per_hour' => 20,
    ],

    // Unauthenticated email-check endpoint. Keyed per IP. The hourly cap targets
    // slow, patient account-enumeration scraping.
    'email_check' => [
        'per_minute' => 5,
        'per_hour' => 30,
    ],

    // Connection-token redemption. Keyed per authenticated user. Makes the
    // 6-digit code infeasible to brute-force within its 1-hour validity.
    'connection_attempts' => [
        'per_minute' => 10,
        'per_hour' => null,
    ],

    // Sync API general throttles.
    'sync_per_user' => [
        'per_minute' => 10,
        'per_hour' => null,
    ],

    'sync_global' => [
        'per_minute' => 60,
        'per_hour' => null,
    ],

    'sync_batch' => [
        'per_minute' => 10,
        'per_hour' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Breeze Login Failure Lockout
    |--------------------------------------------------------------------------
    |
    | Breeze's LoginRequest enforces its own lockout that counts only FAILED
    | login attempts (per email + IP) and clears on success. This is a distinct
    | anti-brute-force control from the `login` route limiter above, which caps
    | all requests. `max_attempts` is the number of failures before lockout.
    |
    */
    'login_failures' => [
        'max_attempts' => 5,
    ],

];
