<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform admin
    |--------------------------------------------------------------------------
    |
    | Created by `php artisan db:seed`. The password is only used when the admin
    | user does not exist yet; change it from the app after the first sign-in.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo customer (local / staging only)
    |--------------------------------------------------------------------------
    |
    | `php artisan demo:customer` creates this business owner with an active
    | Basic plan, paid with Stripe's test card. Refuses to run in production.
    |
    */

    'demo' => [
        'email' => env('DEMO_CUSTOMER_EMAIL'),
        'password' => env('DEMO_CUSTOMER_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketing website
    |--------------------------------------------------------------------------
    |
    | The public site this app is the back end for. Used for the logo link and
    | the Terms of Service / Privacy Policy links on the sign-up page.
    |
    */

    'website_url' => rtrim(env('WEBSITE_URL', 'http://localhost'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Email
    |--------------------------------------------------------------------------
    |
    | admin_copy gets a blind copy (BCC) of every invoice email sent to customers.
    | Password reset emails are never copied.
    |
    */

    'mail' => [
        'admin_copy' => env('MAIL_ADMIN_CC'),
    ],

];
