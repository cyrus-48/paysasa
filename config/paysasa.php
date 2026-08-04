<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The driver used whenever the fluent API is invoked without an explicit
    | Payment::driver('name') call, e.g. Payment::amount(...)->charge().
    |
    */
    'default' => env('PAYSASA_DEFAULT_DRIVER', 'mpesa'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Global switch between 'sandbox' and 'production'. Individual drivers may
    | override this via their own `env` key below, but defaulting it here lets
    | you flip every provider at once for a staging deployment.
    |
    */
    'environment' => env('PAYSASA_ENV', env('APP_ENV') === 'production' ? 'production' : 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYSASA_CURRENCY', 'KES'),

    /*
    |--------------------------------------------------------------------------
    | Callback / Webhook Base URL
    |--------------------------------------------------------------------------
    |
    | Providers call back into your application on this host. Individual
    | driver callback paths are appended to this base, e.g.
    | {base}/paysasa/webhooks/mpesa
    |
    */
    'callback_base_url' => env('PAYSASA_CALLBACK_URL', env('APP_URL')),

    'webhook_route_prefix' => env('PAYSASA_WEBHOOK_PREFIX', 'paysasa/webhooks'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'enabled' => env('PAYSASA_IDEMPOTENCY_ENABLED', true),
        'ttl_seconds' => env('PAYSASA_IDEMPOTENCY_TTL', 86400),
        'store' => env('PAYSASA_IDEMPOTENCY_STORE', env('CACHE_STORE', 'redis')),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Defaults
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => env('PAYSASA_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('PAYSASA_HTTP_CONNECT_TIMEOUT', 10),
        'retry' => [
            'times' => env('PAYSASA_HTTP_RETRY_TIMES', 3),
            'sleep_milliseconds' => env('PAYSASA_HTTP_RETRY_SLEEP', 500),
            'backoff_multiplier' => env('PAYSASA_HTTP_RETRY_BACKOFF', 2),
        ],
        'verify_ssl' => env('PAYSASA_HTTP_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('PAYSASA_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
        'queue' => env('PAYSASA_QUEUE_NAME', 'payments'),
        'webhooks_queue' => env('PAYSASA_WEBHOOKS_QUEUE', 'payment-webhooks'),
        'tries' => env('PAYSASA_JOB_TRIES', 5),
        'backoff' => [10, 30, 60, 300, 900], // seconds, exponential-ish
        'retry_until_minutes' => env('PAYSASA_JOB_RETRY_UNTIL_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('PAYSASA_LOGGING_ENABLED', true),
        'channel' => env('PAYSASA_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),
        'log_raw_payloads' => env('PAYSASA_LOG_RAW_PAYLOADS', true),
        'redact_fields' => ['pin', 'password', 'card_number', 'cvv', 'account_number', 'authorization_code'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting (webhook + outbound API abuse protection)
    |--------------------------------------------------------------------------
    */
    'rate_limiting' => [
        'webhooks' => [
            'enabled' => env('PAYSASA_WEBHOOK_RATE_LIMIT_ENABLED', true),
            'max_attempts' => env('PAYSASA_WEBHOOK_RATE_LIMIT_MAX', 120),
            'decay_seconds' => env('PAYSASA_WEBHOOK_RATE_LIMIT_DECAY', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fraud Detection Hooks
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class names implementing
    | Paysasa\Payments\Contracts\FraudCheck. Each is run, in order, before a
    | charge is dispatched to a driver. Throwing FraudSuspectedException
    | aborts the charge.
    |
    */
    'fraud_checks' => [
        // App\Payments\FraudChecks\VelocityCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    */
    'drivers' => [

        /*
        |----------------------------------------------------------------
        | Mobile Money
        |----------------------------------------------------------------
        */
        'mpesa' => [
            'driver' => \Paysasa\Payments\Drivers\MobileMoney\MpesaDriver::class,
            'env' => env('MPESA_ENV', 'sandbox'), // sandbox|production
            'consumer_key' => env('MPESA_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
            'shortcode' => env('MPESA_SHORTCODE'),
            'till_number' => env('MPESA_TILL_NUMBER'),
            'passkey' => env('MPESA_PASSKEY'),
            'initiator_name' => env('MPESA_INITIATOR_NAME'),
            'initiator_password' => env('MPESA_INITIATOR_PASSWORD'),
            // Either supply a pre-computed SecurityCredential, or a path to Safaricom's
            // public certificate so the driver can encrypt initiator_password itself.
            'security_credential' => env('MPESA_SECURITY_CREDENTIAL'),
            'certificate_path' => env('MPESA_CERTIFICATE_PATH'),
            'b2c_shortcode' => env('MPESA_B2C_SHORTCODE'),
            'b2c_queue_timeout_url' => env('MPESA_B2C_TIMEOUT_URL'),
            'b2c_result_url' => env('MPESA_B2C_RESULT_URL'),
            'stk_callback_url' => env('MPESA_STK_CALLBACK_URL'),
            'c2b_validation_url' => env('MPESA_C2B_VALIDATION_URL'),
            'c2b_confirmation_url' => env('MPESA_C2B_CONFIRMATION_URL'),
            // Comma-separated list of Safaricom source IPs allowed to call your webhook endpoints in production.
            'webhook_ip_allowlist' => array_filter(explode(',', (string) env('MPESA_WEBHOOK_IP_ALLOWLIST', ''))),
            'base_urls' => [
                'sandbox' => 'https://sandbox.safaricom.co.ke',
                'production' => 'https://api.safaricom.co.ke',
            ],
        ],

        'airtel' => [
            'driver' => \Paysasa\Payments\Drivers\MobileMoney\AirtelMoneyDriver::class,
            'env' => env('AIRTEL_ENV', 'sandbox'),
            'client_id' => env('AIRTEL_CLIENT_ID'),
            'client_secret' => env('AIRTEL_CLIENT_SECRET'),
            'country' => env('AIRTEL_COUNTRY', 'KE'),
            'currency' => env('AIRTEL_CURRENCY', 'KES'),
            'callback_url' => env('AIRTEL_CALLBACK_URL'),
            'public_key_id' => env('AIRTEL_PUBLIC_KEY_ID'),
            'webhook_secret' => env('AIRTEL_WEBHOOK_SECRET'),
            'base_urls' => [
                'sandbox' => 'https://openapiuat.airtel.africa',
                'production' => 'https://openapi.airtel.africa',
            ],
        ],

        'tkash' => [
            'driver' => \Paysasa\Payments\Drivers\MobileMoney\TKashDriver::class,
            'env' => env('TKASH_ENV', 'sandbox'),
            'api_key' => env('TKASH_API_KEY'),
            'merchant_code' => env('TKASH_MERCHANT_CODE'),
            'callback_url' => env('TKASH_CALLBACK_URL'),
            'webhook_secret' => env('TKASH_WEBHOOK_SECRET'),
            'base_urls' => [
                'sandbox' => 'https://api.tkash.co.ke/sandbox',
                'production' => 'https://api.tkash.co.ke',
            ],
        ],

        /*
        |----------------------------------------------------------------
        | Card Payments
        |----------------------------------------------------------------
        */
        'stripe' => [
            'driver' => \Paysasa\Payments\Drivers\Cards\StripeDriver::class,
            'public_key' => env('STRIPE_PUBLIC_KEY'),
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        ],

        'pesapal' => [
            'driver' => \Paysasa\Payments\Drivers\Cards\PesapalDriver::class,
            'env' => env('PESAPAL_ENV', 'sandbox'),
            'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
            'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
            'ipn_url' => env('PESAPAL_IPN_URL'),
            'base_urls' => [
                'sandbox' => 'https://cybqa.pesapal.com/pesapalv3',
                'production' => 'https://pay.pesapal.com/v3',
            ],
        ],

        'flutterwave' => [
            'driver' => \Paysasa\Payments\Drivers\Cards\FlutterwaveDriver::class,
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'webhook_secret_hash' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        ],

        'paystack' => [
            'driver' => \Paysasa\Payments\Drivers\Cards\PaystackDriver::class,
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],

        /*
        |----------------------------------------------------------------
        | Digital Wallets
        |----------------------------------------------------------------
        |
        | Google Pay and Apple Pay are tokenization layers, not independent
        | settlement rails: the wallet returns an encrypted payment token
        | which must still be authorized/captured through an underlying card
        | acquirer. Both drivers below decrypt/validate the wallet token and
        | delegate settlement to the configured `gateway` driver.
        |
        */
        'google_pay' => [
            'driver' => \Paysasa\Payments\Drivers\Wallets\GooglePayDriver::class,
            'gateway' => env('GOOGLE_PAY_GATEWAY', 'stripe'),
            'merchant_id' => env('GOOGLE_PAY_MERCHANT_ID'),
            'merchant_name' => env('GOOGLE_PAY_MERCHANT_NAME', env('APP_NAME')),
            'gateway_merchant_id' => env('GOOGLE_PAY_GATEWAY_MERCHANT_ID'),
        ],

        'apple_pay' => [
            'driver' => \Paysasa\Payments\Drivers\Wallets\ApplePayDriver::class,
            'gateway' => env('APPLE_PAY_GATEWAY', 'stripe'),
            'merchant_id' => env('APPLE_PAY_MERCHANT_ID'),
            'merchant_certificate_path' => env('APPLE_PAY_MERCHANT_CERT_PATH'),
            'merchant_key_path' => env('APPLE_PAY_MERCHANT_KEY_PATH'),
            'domain_association_path' => env('APPLE_PAY_DOMAIN_ASSOCIATION_PATH'),
        ],

        /*
        |----------------------------------------------------------------
        | Banking
        |----------------------------------------------------------------
        |
        | Kenyan bank rails (PesaLink, EFT, RTGS, Virtual Accounts, direct
        | bank host-to-host APIs) have no single public standard the way
        | Daraja or Stripe do — each acquiring bank issues its own API under
        | a commercial agreement. The drivers below are adapters against
        | the Contracts\BankingGateway contract; ship one concrete
        | implementation per bank you integrate with by extending
        | Drivers\Banking\AbstractBankingDriver.
        |
        */
        'pesalink' => [
            'driver' => \Paysasa\Payments\Drivers\Banking\PesaLinkDriver::class,
            'env' => env('PESALINK_ENV', 'sandbox'),
            'institution_code' => env('PESALINK_INSTITUTION_CODE'),
            'api_key' => env('PESALINK_API_KEY'),
            'api_secret' => env('PESALINK_API_SECRET'),
            'webhook_secret' => env('PESALINK_WEBHOOK_SECRET'),
            'base_urls' => [
                'sandbox' => env('PESALINK_SANDBOX_URL'),
                'production' => env('PESALINK_PRODUCTION_URL'),
            ],
        ],

        'eft' => [
            'driver' => \Paysasa\Payments\Drivers\Banking\EftDriver::class,
            'bank_code' => env('EFT_BANK_CODE'),
            'api_key' => env('EFT_API_KEY'),
            'base_url' => env('EFT_BASE_URL'),
            'webhook_secret' => env('EFT_WEBHOOK_SECRET'),
        ],

        'rtgs' => [
            'driver' => \Paysasa\Payments\Drivers\Banking\RtgsDriver::class,
            'bank_code' => env('RTGS_BANK_CODE'),
            'api_key' => env('RTGS_API_KEY'),
            'base_url' => env('RTGS_BASE_URL'),
            'webhook_secret' => env('RTGS_WEBHOOK_SECRET'),
            'cutoff_time' => env('RTGS_CUTOFF_TIME', '15:00'),
        ],

        'virtual_account' => [
            'driver' => \Paysasa\Payments\Drivers\Banking\VirtualAccountDriver::class,
            'bank_code' => env('VIRTUAL_ACCOUNT_BANK_CODE'),
            'api_key' => env('VIRTUAL_ACCOUNT_API_KEY'),
            'base_url' => env('VIRTUAL_ACCOUNT_BASE_URL'),
            'account_prefix' => env('VIRTUAL_ACCOUNT_PREFIX', 'PSS'),
            'webhook_secret' => env('VIRTUAL_ACCOUNT_WEBHOOK_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Registration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => env('PAYSASA_ROUTES_ENABLED', true),
        'middleware' => ['api'],
        'domain' => env('PAYSASA_ROUTES_DOMAIN'),
    ],
];
