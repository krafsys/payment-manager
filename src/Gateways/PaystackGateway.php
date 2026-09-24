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
 * @see https://paystack.com/docs/api/transaction/
 */
final class PaystackGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function getName(): string
    {
        return 'paystack';
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $reference = $request->referenceOrGenerated();

        $response = $this->http->request('POST', self::BASE_URL . '/transaction/initialize', $this->headers(), [
            'email' => $request->email,
            'amount' => $this->toMinorUnit($request->amount),
            'currency' => $request->currency,
            'reference' => $reference,
            'callback_url' => $request->redirectUrl,
            'metadata' => $request->metadata,
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new PaymentResponse(
            successful: (bool) ($response->json['status'] ?? false),
            gateway: $this->getName(),
            reference: (string) ($data['reference'] ?? $reference),
            checkoutUrl: $data['authorization_url'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function verify(string $reference): VerificationResponse
    {
        $response = $this->http->request(
            'GET',
            self::BASE_URL . '/transaction/verify/' . rawurlencode($reference),
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
            amount: $this->fromMinorUnit($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            paidAt: $data['paid_at'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $body = ['transaction' => $request->reference];

        if ($request->amount !== null) {
            $body['amount'] = $this->toMinorUnit($request->amount);
        }

        if ($request->reason !== null) {
            $body['merchant_note'] = $request->reason;
        }

        $response = $this->http->request('POST', self::BASE_URL . '/refund', $this->headers(), $body);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new RefundResponse(
            successful: (bool) ($response->json['status'] ?? false),
            gateway: $this->getName(),
            reference: $request->reference,
            refundReference: isset($data['id']) ? (string) $data['id'] : null,
            status: (string) ($data['status'] ?? 'unknown'),
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $computed = hash_hmac('sha512', $rawBody, $this->config->secretKey);

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
            'abandoned' => TransactionStatus::Abandoned,
            'reversed' => TransactionStatus::Reversed,
            'pending', 'queued' => TransactionStatus::Pending,
            default => TransactionStatus::Unknown,
        };
    }
}
