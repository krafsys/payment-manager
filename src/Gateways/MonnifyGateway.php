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
use Krafsys\PaymentManager\Exceptions\InvalidCredentialsException;

/**
 * @see https://developers.monnify.com/api/
 *
 * Unlike the other gateways, Monnify requires exchanging your API key +
 * secret key for a short-lived Bearer access token (POST /auth/login)
 * before any other call. This class fetches and caches that token in
 * memory for the lifetime of the instance, refetching once it's close
 * to expiry.
 */
final class MonnifyGateway extends AbstractGateway
{
    private const LIVE_BASE_URL = 'https://api.monnify.com';
    private const SANDBOX_BASE_URL = 'https://sandbox.monnify.com';

    private ?string $cachedToken = null;
    private int $tokenExpiresAt = 0;

    public function getName(): string
    {
        return 'monnify';
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $reference = $request->referenceOrGenerated();

        $response = $this->http->request('POST', $this->baseUrl() . '/api/v1/merchant/transactions/init-transaction', $this->authHeaders(), [
            'amount' => $request->amount,
            'customerName' => $request->customerName ?? $request->email,
            'customerEmail' => $request->email,
            'paymentReference' => $reference,
            'paymentDescription' => $request->description ?? 'Payment',
            'currencyCode' => $request->currency,
            'contractCode' => $this->config->contractCode,
            'redirectUrl' => $request->redirectUrl,
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $body = $response->json['responseBody'] ?? [];

        return new PaymentResponse(
            successful: (bool) ($response->json['requestSuccessful'] ?? false),
            gateway: $this->getName(),
            reference: (string) ($body['paymentReference'] ?? $reference),
            checkoutUrl: $body['checkoutUrl'] ?? null,
            message: (string) ($response->json['responseMessage'] ?? ''),
            raw: $response->json
        );
    }

    public function verify(string $reference): VerificationResponse
    {
        $response = $this->http->request(
            'GET',
            $this->baseUrl() . '/api/v1/merchant/transactions/query?paymentReference=' . rawurlencode($reference),
            $this->authHeaders()
        );

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $body = $response->json['responseBody'] ?? [];

        return new VerificationResponse(
            status: $this->mapStatus((string) ($body['paymentStatus'] ?? '')),
            gateway: $this->getName(),
            reference: (string) ($body['paymentReference'] ?? $reference),
            amount: (float) ($body['amountPaid'] ?? 0),
            currency: (string) ($body['currencyCode'] ?? 'NGN'),
            paidAt: $body['paidOn'] ?? null,
            message: (string) ($response->json['responseMessage'] ?? ''),
            raw: $response->json
        );
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $verification = $this->verify($request->reference);
        $transactionReference = $verification->raw['responseBody']['transactionReference'] ?? null;

        if ($transactionReference === null) {
            return new RefundResponse(
                successful: false,
                gateway: $this->getName(),
                reference: $request->reference,
                refundReference: null,
                status: 'failed',
                message: 'Could not resolve the Monnify transactionReference for this paymentReference; refund not attempted.',
                raw: $verification->raw
            );
        }

        $response = $this->http->request('POST', $this->baseUrl() . '/api/v2/refunds/initiate-refund', $this->authHeaders(), [
            'transactionReference' => $transactionReference,
            'refundAmount' => $request->amount,
            'refundReason' => $request->reason ?? 'Refund requested',
            'customerNote' => $request->reason ?? 'Refund requested',
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $body = $response->json['responseBody'] ?? [];

        return new RefundResponse(
            successful: (bool) ($response->json['requestSuccessful'] ?? false),
            gateway: $this->getName(),
            reference: $request->reference,
            refundReference: isset($body['refundReference']) ? (string) $body['refundReference'] : null,
            status: (string) ($body['refundStatus'] ?? 'unknown'),
            message: (string) ($response->json['responseMessage'] ?? ''),
            raw: $response->json
        );
    }

    /**
     * IMPORTANT: Monnify computes `transactionHash` as a hash of your client
     * secret plus several payload fields, but the exact field order used by
     * Monnify's current webhook implementation should be confirmed against
     * your dashboard's live webhook documentation before relying on this in
     * production — treat this implementation as a starting point, not a
     * guaranteed-correct reference.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return false;
        }

        $eventData = $payload['eventData'] ?? $payload;

        $computed = hash(
            'sha512',
            $this->config->secretKey
            . (string) ($eventData['paymentReference'] ?? '')
            . (string) ($eventData['amountPaid'] ?? '')
            . (string) ($eventData['paidOn'] ?? '')
            . (string) ($eventData['transactionReference'] ?? '')
        );

        return $this->timingSafeEquals($computed, $signature);
    }

    public function parseWebhookPayload(array $payload): WebhookEvent
    {
        $data = $payload['eventData'] ?? $payload;

        return new WebhookEvent(
            gateway: $this->getName(),
            event: (string) ($payload['eventType'] ?? ''),
            reference: isset($data['paymentReference']) ? (string) $data['paymentReference'] : null,
            status: $this->mapStatus((string) ($data['paymentStatus'] ?? '')),
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
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->getAccessToken()];
    }

    private function getAccessToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }

        if ($this->config->apiKey === null) {
            throw InvalidCredentialsException::forGateway($this->getName());
        }

        $credentials = base64_encode($this->config->apiKey . ':' . $this->config->secretKey);

        $response = $this->http->request('POST', $this->baseUrl() . '/api/v1/auth/login', [
            'Authorization' => 'Basic ' . $credentials,
        ]);

        if (!$response->successful()) {
            $this->failOn($response);
        }

        $body = $response->json['responseBody'] ?? [];
        $token = (string) ($body['accessToken'] ?? '');

        if ($token === '') {
            throw InvalidCredentialsException::forGateway($this->getName());
        }

        // Refresh a little early (55 of the usual 60 minute lifetime) to avoid edge-of-expiry failures.
        $expiresIn = (int) ($body['expiresIn'] ?? 3300);
        $this->cachedToken = $token;
        $this->tokenExpiresAt = time() + max(60, $expiresIn - 300);

        return $token;
    }

    private function mapStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'PAID', 'OVERPAID' => TransactionStatus::Success,
            'FAILED', 'EXPIRED' => TransactionStatus::Failed,
            'PARTIALLY_PAID', 'PENDING' => TransactionStatus::Pending,
            default => TransactionStatus::Unknown,
        };
    }
}
