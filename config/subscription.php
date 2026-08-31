<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trial and grace windows
    |--------------------------------------------------------------------------
    |
    | New farms start on the Basic plan entitlements for the trial window. Once
    | a paid period lapses the farm keeps full access for the grace window
    | before dropping to read-only.
    |
    */
    'trial_days' => env('SUBSCRIPTION_TRIAL_DAYS', 14),
    'grace_days' => env('SUBSCRIPTION_GRACE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Billing page
    |--------------------------------------------------------------------------
    |
    | Where the frontend surfaces upgrade prompts. Returned alongside
    | entitlement errors so clients can link straight to checkout.
    |
    */
    'billing_url' => env('SUBSCRIPTION_BILLING_URL', '/dashboard/settings/billing'),

    /*
    |--------------------------------------------------------------------------
    | Trial entitlements
    |--------------------------------------------------------------------------
    |
    | The plan new farms are trialled on. AI stays behind a paid Premium plan
    | (or an admin waiver), so a Basic trial never unlocks AI features.
    |
    */
    'trial_plan' => env('SUBSCRIPTION_TRIAL_PLAN', 'basic'),
];
