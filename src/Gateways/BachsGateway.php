<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Gateways;

use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\DTO\PaymentResponse;
use Krafsys\PaymentManager\DTO\RefundRequest;
use Krafsys\PaymentManager\DTO\RefundResponse;
use Krafsys\PaymentManager\DTO\VerificationResponse;
use Krafsys\PaymentManager\DTO\WebhookEvent;
use Krafsys\PaymentManager\Enums\TransactionStatus;

/**
 * @see https://docs.bachs.io
 *
 * Confirmed against docs.bachs.io/introduction and docs.bachs.io/connect/split-payments/separate-transfers:
 *   POST {base}/v1/checkout-sessions
 *   GET  {base}/v1/checkout-sessions/{checkout_id}
 * where {base} is https://sandbox-api.bachs.io or https://api.bachs.io. All amounts are
 * decimal STRINGS at the currency's own precision (e.g. "5000.00"), never floats or minor units.
 *
 * IMPORTANT — unlike the other four gateways, Bachs does not support looking a
 * transaction up by a reference you invented yourself. verify() takes Bachs's own
 * `checkout_id` (e.g. "chk_5b6e19d42f8a"), which only exists after initialize()
 * returns it. Store the `reference` PaymentResponse gives you back for Bachs — it
 * *is* that checkout_id — and pass that same value into verify() later, not your
 * own generated reference.
 *
 * Bachs is also architected around pre-created Products; this adapter uses the
 * documented `pricing: {amount, currency}` root-level field to price the session
 * ad-hoc without a Product, which matches the flat "amount + currency" model this
 * package uses everywhere else. If your Bachs account requires checkouts to go
 * through a Product, use `product_cart` with a `pricing` override per docs.bachs.io
 * instead — see the README for that shape.
 *
 * Confidence note: refund() and the exact webhook header names are the two pieces
 * still not confirmed against a primary Bachs reference page — verify both against
 * your dashboard before depending on them in production.
 */
final class BachsGateway extends AbstractGateway
{
    private const LIVE_BASE_URL = 'https://api.bachs.io/v1';
    private const SANDBOX_BASE_URL = 'https://sandbox-api.bachs.io/v1';

    public function getName(): string
    {
        return 'bachs';
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $reference = $request->referenceOrGenerated();

        $response = $this->http->request('POST', $this->baseUrl() . '/checkout-sessions', $this->headers(), [
            'pricing' => [
                'amount' => $this->toDecimalString($request->amount),
                'currency' => $request->currency,
            ],
            'customer' => array_filter([
                'email' => $request->email,
                'name' => $request->customerName,
            ]),
            'success_url' => $request->redirectUrl,
            'cancel_url' => $request->redirectUrl,
            'metadata' => array_merge($request->metadata, ['reference' => $reference]),
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $this->unwrap($response->json);

        // Bachs's own checkout_id is what verify() needs later — see class docblock.
        $checkoutId = (string) ($data['checkout_id'] ?? $reference);

        return new PaymentResponse(
            successful: true,
            gateway: $this->getName(),
            reference: $checkoutId,
            checkoutUrl: $data['checkout_url'] ?? null,
            message: (string) ($response->json['message'] ?? 'Checkout session created.'),
            raw: $response->json
        );
    }

    /**
     * @param string $reference  Bachs's checkout_id (e.g. "chk_...") returned by initialize() — not your own reference.
     */
    public function verify(string $reference): VerificationResponse
    {
        $response = $this->http->request(
            'GET',
            $this->baseUrl() . '/checkout-sessions/' . rawurlencode($reference),
            $this->headers()
        );

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $this->unwrap($response->json);

        return new VerificationResponse(
            status: $this->mapStatus((string) ($data['status'] ?? '')),
            gateway: $this->getName(),
            reference: (string) ($data['checkout_id'] ?? $reference),
            amount: (float) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            paidAt: $data['paid_at'] ?? $data['when_completed'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    /**
     * Not confirmed against a primary Bachs reference page — verify the exact
     * path and required identifier (checkout_id vs. an underlying payment id)
     * against your dashboard's API reference before relying on this.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        $body = array_filter([
            'checkout_id' => $request->reference,
            'amount' => $request->amount !== null ? $this->toDecimalString($request->amount) : null,
            'reason' => $request->reason,
        ]);

        $response = $this->http->request('POST', $this->baseUrl() . '/refunds', $this->headers(), $body);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $this->unwrap($response->json);

        return new RefundResponse(
            successful: true,
            gateway: $this->getName(),
            reference: $request->reference,
            refundReference: isset($data['id']) ? (string) $data['id'] : null,
            status: (string) ($data['status'] ?? 'unknown'),
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    /**
     * Bachs's WooCommerce integration documents its webhook signature as an
     * HMAC-SHA256 over `{timestamp}.{body}` (a Stripe-style scheme), not the
     * raw body alone. Pass $rawBody as that already-concatenated
     * "{timestamp}.{body}" string — read the timestamp from whichever header
     * your Bachs webhook settings document (not confirmed here) and
     * concatenate it yourself before calling this method.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $this->config->webhookSecret === null) {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $this->config->webhookSecret);

        return $this->timingSafeEquals($computed, $signature);
    }

    public function parseWebhookPayload(array $payload): WebhookEvent
    {
        $data = $this->unwrap($payload);

        return new WebhookEvent(
            gateway: $this->getName(),
            event: (string) ($payload['event'] ?? ($payload['type'] ?? '')),
            reference: isset($data['checkout_id']) ? (string) $data['checkout_id'] : null,
            status: $this->mapStatus((string) ($data['status'] ?? '')),
            payload: $payload
        );
    }

    private function baseUrl(): string
    {
        return $this->config->baseUrlOverride
            ?? ($this->config->sandbox ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->config->secretKey];
    }

    /**
     * Bachs requires money as a decimal string at the currency's own precision
     * (e.g. "5000.00"), never a float or a minor-unit integer.
     */
    private function toDecimalString(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Bachs's checkout-session responses are unwrapped JSON (see the confirmed
     * example in the class docblock), but this tolerates a `data` envelope too
     * in case a different endpoint wraps its response.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function unwrap(array $json): array
    {
        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    private function mapStatus(string $status): TransactionStatus
    {
        return match (strtolower($status)) {
            'success', 'successful', 'paid', 'complete', 'completed', 'succeeded' => TransactionStatus::Success,
            'failed' => TransactionStatus::Failed,
            'expired', 'cancelled' => TransactionStatus::Abandoned,
            'pending', 'open' => TransactionStatus::Pending,
            default => TransactionStatus::Unknown,
        };
    }
}
