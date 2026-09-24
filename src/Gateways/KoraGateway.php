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
 * @see https://docs.korahq.com/
 */
final class KoraGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.korapay.com/merchant/api/v1';

    public function getName(): string
    {
        return 'kora';
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $reference = $request->referenceOrGenerated();

        $response = $this->http->request('POST', self::BASE_URL . '/charges/initialize', $this->headers(), [
            'amount' => $request->amount,
            'currency' => $request->currency,
            'reference' => $reference,
            'customer' => array_filter([
                'name' => $request->customerName,
                'email' => $request->email,
            ]),
            'redirect_url' => $request->redirectUrl,
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new PaymentResponse(
            successful: (bool) ($response->json['status'] ?? false),
            gateway: $this->getName(),
            reference: (string) ($data['reference'] ?? $reference),
            checkoutUrl: $data['checkout_url'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function verify(string $reference): VerificationResponse
    {
        $response = $this->http->request(
            'GET',
            self::BASE_URL . '/charges/' . rawurlencode($reference),
            $this->headers()
        );

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

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
        $body = ['transaction_reference' => $request->reference];

        if ($request->amount !== null) {
            $body['amount'] = $request->amount;
        }

        if ($request->reason !== null) {
            $body['reason'] = $request->reason;
        }

        $response = $this->http->request('POST', self::BASE_URL . '/refunds/initiate', $this->headers(), $body);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new RefundResponse(
            successful: (bool) ($response->json['status'] ?? false),
            gateway: $this->getName(),
            reference: $request->reference,
            refundReference: isset($data['reference']) ? (string) $data['reference'] : null,
            status: (string) ($data['status'] ?? 'unknown'),
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    /**
     * Kora signs webhook payloads with an HMAC-SHA256 of the `data` object
     * (not the full envelope), sent in the `Kora-Signature` header. Pass
     * that raw JSON `data` sub-object here as $rawBody.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $this->config->secretKey);

        return $this->timingSafeEquals($computed, $signature);
    }

    public function parseWebhookPayload(array $payload): WebhookEvent
    {
        $data = $payload['data'] ?? [];

        return new WebhookEvent(
            gateway: $this->getName(),
            event: (string) ($payload['event'] ?? ''),
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            status: $this->mapStatus((string) ($data['status'] ?? '')),
            payload: $payload
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->config->secretKey];
    }

    private function mapStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'success' => TransactionStatus::Success,
            'failed' => TransactionStatus::Failed,
            'processing' => TransactionStatus::Processing,
            'pending' => TransactionStatus::Pending,
            default => TransactionStatus::Unknown,
        };
    }
}
