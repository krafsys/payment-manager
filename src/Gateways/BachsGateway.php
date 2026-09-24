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
 * @see https://bachs.io
 *
 * IMPORTANT — lower confidence than the other four adapters: Bachs is
 * architected around products/checkout sessions rather than a flat
 * "amount + currency" charge like Paystack/Flutterwave/Monnify/Kora, and
 * its public API reference is thinner than the others. This adapter
 * normalizes a PaymentRequest into a single ad-hoc line item on a
 * checkout session so it honors the same GatewayInterface contract, but
 * the exact request/response field names here should be verified against
 * your Bachs dashboard's live API docs before relying on this in
 * production — particularly the response envelope shape, the
 * verify-by-reference lookup, and the webhook signature scheme.
 */
final class BachsGateway extends AbstractGateway
{
    private const LIVE_BASE_URL = 'https://api.bachs.io';
    private const SANDBOX_BASE_URL = 'https://sandbox-api.bachs.io';

    public function getName(): string
    {
        return 'bachs';
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $reference = $request->referenceOrGenerated();

        $response = $this->http->request('POST', $this->baseUrl() . '/checkout_sessions', $this->headers(), [
            'reference' => $reference,
            'customer' => array_filter([
                'email' => $request->email,
                'name' => $request->customerName,
            ]),
            'line_items' => [
                array_filter([
                    'name' => $request->description ?? 'Payment',
                    'unit_amount' => $request->amount,
                    'currency' => $request->currency,
                    'quantity' => 1,
                ]),
            ],
            'success_url' => $request->redirectUrl,
            'metadata' => $request->metadata,
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $this->unwrap($response->json);

        return new PaymentResponse(
            successful: true,
            gateway: $this->getName(),
            reference: (string) ($data['reference'] ?? $reference),
            checkoutUrl: $data['checkout_url'] ?? $data['url'] ?? null,
            message: (string) ($response->json['message'] ?? 'Checkout session created.'),
            raw: $response->json
        );
    }

    public function verify(string $reference): VerificationResponse
    {
        $response = $this->http->request(
            'GET',
            $this->baseUrl() . '/checkout_sessions/' . rawurlencode($reference),
            $this->headers()
        );

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $this->unwrap($response->json);

        return new VerificationResponse(
            status: $this->mapStatus((string) ($data['status'] ?? '')),
            gateway: $this->getName(),
            reference: (string) ($data['reference'] ?? $reference),
            amount: (float) ($data['amount_paid'] ?? $data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            paidAt: $data['paid_at'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $body = array_filter([
            'reference' => $request->reference,
            'amount' => $request->amount,
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
     * Best-effort HMAC-SHA256 comparison against a `Bachs-Signature` style
     * header. Confirm the header name and algorithm against current Bachs
     * docs before depending on this for production webhook security.
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
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
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
     * Bachs may or may not wrap responses in a `data` envelope — this
     * tolerates either shape rather than assuming one.
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
            'success', 'successful', 'paid', 'complete', 'completed' => TransactionStatus::Success,
            'failed' => TransactionStatus::Failed,
            'expired', 'cancelled' => TransactionStatus::Abandoned,
            'pending', 'open' => TransactionStatus::Pending,
            default => TransactionStatus::Unknown,
        };
    }
}
