<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | Which gateway issues dynamic QRIS codes. "fake" needs no account and
    | settles a code when the cashier presses the test button on the payment
    | screen, so the whole flow can be tried on a laptop. Switch to "xendit"
    | once the sandbox keys are in .env.
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // How long a dynamic QRIS code stays payable. Short on purpose: a code
    // left on a screen should not be scannable by the next guest in line.
    'qris_expiry_minutes' => (int) env('QRIS_EXPIRY_MINUTES', 10),

    // Ask the gateway directly at most this often while a code is on screen.
    // Webhooks are the fast path; this is the safety net when they cannot
    // reach the server, which is the normal case on a laptop without a tunnel.
    'status_check_seconds' => (int) env('QRIS_STATUS_CHECK_SECONDS', 10),

    'xendit' => [
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        'secret_key' => env('XENDIT_SECRET_KEY'),

        // The "callback verification token" from the Xendit dashboard. Every
        // webhook carries it in the x-callback-token header.
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),

        'timeout' => (int) env('XENDIT_TIMEOUT', 15),
    ],

    'fake' => [
        // Signs the fake gateway's webhooks, so the test button goes through
        // the same signature check a real webhook does.
        'webhook_secret' => env('FAKE_GATEWAY_SECRET', 'meja-fake-gateway'),

        // Percent the fake gateway keeps as its fee in settlement reports.
        'fee_percent' => 0.7,
    ],

];
