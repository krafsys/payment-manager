# krafsys/payment-manager

A single, consistent PHP interface for five Nigerian payment gateways — **Paystack**, **Flutterwave**, **Monnify**, **Kora (KoraPay)**, and **Bachs**. Initialize, verify, refund, and handle webhooks the same way regardless of which gateway is behind it, and switch gateways without touching your application code.

- **Zero runtime dependencies** — built on `ext-curl`, not Guzzle or PSR-18. Installing this package installs nothing else.
- **Framework-agnostic core** with an optional Laravel service provider + facade.
- Requires **PHP 8.1+**.

> ⚠️ **Before going live:** this package was built from each provider's public API documentation, but payment provider APIs change and some details (particularly **Monnify's webhook hash formula** and **all of the Bachs integration**, which is architected differently from the other four — see `src/Gateways/BachsGateway.php`) should be verified against your dashboard's current docs and tested against sandbox credentials before you rely on them with real money.

## Installation

```bash
composer require krafsys/payment-manager
```

## Quick start (plain PHP)

```php
use Krafsys\PaymentManager\PaymentManager;
use Krafsys\PaymentManager\DTO\PaymentRequest;

$manager = new PaymentManager([
    'paystack' => [
        'secret_key' => 'sk_test_xxx',
    ],
    'flutterwave' => [
        'secret_key' => 'FLWSECK_TEST-xxx',
        'webhook_secret' => 'your-configured-verif-hash',
    ],
    'monnify' => [
        'api_key' => 'MK_TEST_xxx',
        'secret_key' => 'xxx',
        'contract_code' => '123456789',
        'sandbox' => true,
    ],
    'kora' => [
        'secret_key' => 'sk_test_xxx',
    ],
    'bachs' => [
        'secret_key' => 'sk_test_xxx',
        'webhook_secret' => 'whsec_xxx',
        'sandbox' => true,
    ],
]);

$response = $manager->gateway('paystack')->initialize(new PaymentRequest(
    amount: 5000.00,          // major unit — Naira, not kobo. Each adapter converts internally.
    email: 'customer@example.com',
    reference: 'order_1029',  // optional — omit to auto-generate
    redirectUrl: 'https://yourapp.com/payments/callback',
    description: 'Order #1029',
));

if ($response->successful) {
    header('Location: ' . $response->checkoutUrl);
    exit;
}
```

Every gateway returns the same `PaymentResponse` shape, so this code doesn't change if you swap `'paystack'` for `'kora'`.

## Verifying a transaction

```php
$result = $manager->gateway('paystack')->verify('order_1029');

if ($result->isSuccessful()) {
    // $result->amount, $result->currency, $result->paidAt are all normalized
}
```

## Refunds

```php
use Krafsys\PaymentManager\DTO\RefundRequest;

$refund = $manager->gateway('paystack')->refund(new RefundRequest(
    reference: 'order_1029',
    amount: 1000.00,   // omit for a full refund
    reason: 'Customer requested cancellation',
));
```

## Webhooks

Every gateway adapter exposes the same two methods: verify the signature first, **then** parse the payload. Never trust a webhook you haven't verified.

```php
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null; // header name differs per gateway, see table below

$gateway = $manager->gateway('paystack');

if (!$gateway->verifyWebhookSignature($rawBody, $signature)) {
    http_response_code(401);
    exit;
}

$event = $gateway->parseWebhookPayload(json_decode($rawBody, true));

if ($event->status === \Krafsys\PaymentManager\Enums\TransactionStatus::Success) {
    // fulfil the order using $event->reference
}
```

| Gateway | Webhook header to read | Verification method |
|---|---|---|
| Paystack | `x-paystack-signature` | HMAC-SHA512 of the raw body with your secret key |
| Flutterwave | `verif-hash` | Direct comparison against the hash you set on your dashboard (`webhook_secret` config) |
| Monnify | `monnify-signature` (confirm in your dashboard) | SHA512 of secret key + payload fields — **verify field order against current docs** |
| Kora | `Kora-Signature` | HMAC-SHA256 of the payload's `data` object with your secret key |
| Bachs | provider-specific — **verify against current docs** | HMAC-SHA256 comparison, `webhook_secret` config (best-effort implementation) |

## Laravel usage

The service provider and facade are auto-discovered once you `composer require laravel/framework` alongside this package (it's a suggested, not required, dependency).

Publish the config file:

```bash
php artisan vendor:publish --tag=payment-manager-config
```

Set your credentials in `.env` (`PAYSTACK_SECRET_KEY`, `FLUTTERWAVE_SECRET_KEY`, `MONNIFY_API_KEY`, `MONNIFY_SECRET_KEY`, `MONNIFY_CONTRACT_CODE`, `KORA_SECRET_KEY`, `BACHS_SECRET_KEY`, etc. — see `config/payment-manager.php` for the full list), then:

```php
use Krafsys\PaymentManager\Laravel\Facades\PaymentManager;

$response = PaymentManager::gateway('flutterwave')->initialize($paymentRequest);
```

Or inject `Krafsys\PaymentManager\PaymentManager` via the container as normal.

## Gateway-specific notes

- **Paystack** and **Kora** — straightforward amount + currency charges, matches the package's normalized model closely.
- **Flutterwave** — refunds require Flutterwave's internal transaction id, not your reference, so `refund()` transparently calls `verify()` first to resolve it (one extra API call).
- **Monnify** — requires exchanging your API key/secret for a short-lived Bearer token before every other call. The adapter fetches and caches this token in memory (refetching ~5 minutes before expiry), so you don't need to manage it yourself.
- **Bachs** — is architected around products/checkout sessions rather than a flat charge. This adapter builds a single ad-hoc line item per payment to fit the common interface. Treat this integration as a starting scaffold and confirm the exact request/response field names against Bachs's current API reference before production use.

## Testing this package

```bash
composer install
composer test
```

Tests run entirely against a fake in-memory HTTP client (`tests/Fakes/FakeHttpClient.php`) — no network calls, no real API keys needed.

## License

MIT.
