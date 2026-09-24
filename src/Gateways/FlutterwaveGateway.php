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
 * @see https://developer.flutterwave.com/docs/making-payments
 *
 * Note: Flutterwave's refund endpoint needs Flutterwave's own internal
 * transaction id, not your merchant reference — so refund() transparently
 * verifies first to resolve the id, then issues the refund.
 */
final class FlutterwaveGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    public function getName(): string
    {
        return 'flutterwave';
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $reference = $request->referenceOrGenerated();

        $response = $this->http->request('POST', self::BASE_URL . '/payments', $this->headers(), [
            'tx_ref' => $reference,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'redirect_url' => $request->redirectUrl,
            'customer' => array_filter([
                'email' => $request->email,
                'name' => $request->customerName,
            ]),
            'meta' => $request->metadata,
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new PaymentResponse(
            successful: ($response->json['status'] ?? '') === 'success',
            gateway: $this->getName(),
            reference: $reference,
            checkoutUrl: $data['link'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function verify(string $reference): VerificationResponse
    {
        $response = $this->http->request(
            'GET',
            self::BASE_URL . '/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference),
            $this->headers()
        );

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new VerificationResponse(
            status: $this->mapStatus((string) ($data['status'] ?? '')),
            gateway: $this->getName(),
            reference: (string) ($data['tx_ref'] ?? $reference),
            amount: (float) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            paidAt: $data['created_at'] ?? null,
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $verification = $this->verify($request->reference);
        $flwId = $verification->raw['data']['id'] ?? null;

        if ($flwId === null) {
            return new RefundResponse(
                successful: false,
                gateway: $this->getName(),
                reference: $request->reference,
                refundReference: null,
                status: 'failed',
                message: 'Could not resolve the Flutterwave transaction id for this reference; refund not attempted.',
                raw: $verification->raw
            );
        }

        $body = [];
        if ($request->amount !== null) {
            $body['amount'] = $request->amount;
        }

        $response = $this->http->request('POST', self::BASE_URL . '/transactions/' . $flwId . '/refund', $this->headers(), $body);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $data = $response->json['data'] ?? [];

        return new RefundResponse(
            successful: ($response->json['status'] ?? '') === 'success',
            gateway: $this->getName(),
            reference: $request->reference,
            refundReference: isset($data['id']) ? (string) $data['id'] : null,
            status: (string) ($data['status'] ?? 'unknown'),
            message: (string) ($response->json['message'] ?? ''),
            raw: $response->json
        );
    }

    /**
     * Flutterwave doesn't HMAC-sign webhooks — it echoes back a static
     * secret hash you configure on your dashboard, in the `verif-hash`
     * header, for you to compare directly.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $this->config->webhookSecret === null) {
            return false;
        }

        return $this->timingSafeEquals($this->config->webhookSecret, $signature);
    }

    public function parseWebhookPayload(array $payload): WebhookEvent
    {
        $data = $payload['data'] ?? [];

        return new WebhookEvent(
            gateway: $this->getName(),
            event: (string) ($payload['event'] ?? ($payload['event.type'] ?? '')),
            reference: isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
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
            'successful', 'success' => TransactionStatus::Success,
            'failed' => TransactionStatus::Failed,
            'cancelled' => TransactionStatus::Abandoned,
            'pending' => TransactionStatus::Pending,
            default => TransactionStatus::Unknown,
        };
    }
}
