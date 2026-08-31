<?php

return [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'base_url' => rtrim(env('PAYSTACK_BASE_URL', 'https://api.paystack.co'), '/'),
    'timeout' => env('PAYSTACK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Checkout callback
    |--------------------------------------------------------------------------
    |
    | Where Paystack returns the customer after payment. The farm id is appended
    | so the billing page can refresh the right subscription.
    |
    */
    'callback_url' => env('PAYSTACK_CALLBACK_URL', env('FRONTEND_URL', 'http://localhost:5173').'/dashboard/settings/billing'),
];
