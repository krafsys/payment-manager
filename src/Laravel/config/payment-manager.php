<?php

declare(strict_types=1);

return [

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
    ],

    'monnify' => [
        'api_key' => env('MONNIFY_API_KEY'),
        'secret_key' => env('MONNIFY_SECRET_KEY'),
        'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        'sandbox' => env('MONNIFY_SANDBOX', false),
    ],

    'kora' => [
        'secret_key' => env('KORA_SECRET_KEY'),
        'public_key' => env('KORA_PUBLIC_KEY'),
    ],

    'bachs' => [
        'secret_key' => env('BACHS_SECRET_KEY'),
        'webhook_secret' => env('BACHS_WEBHOOK_SECRET'),
        'sandbox' => env('BACHS_SANDBOX', false),
    ],

];
