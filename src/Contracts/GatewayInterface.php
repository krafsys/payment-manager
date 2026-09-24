<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Contracts;

use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\DTO\PaymentResponse;
use Krafsys\PaymentManager\DTO\RefundRequest;
use Krafsys\PaymentManager\DTO\RefundResponse;
use Krafsys\PaymentManager\DTO\VerificationResponse;
use Krafsys\PaymentManager\DTO\WebhookEvent;

interface GatewayInterface
{
    public function getName(): string;

    /**
     * Start a payment and get back a checkout/authorization URL to redirect the customer to.
     */
    public function initialize(PaymentRequest $request): PaymentResponse;

    /**
     * Confirm the current status of a transaction by your own reference.
     */
    public function verify(string $reference): VerificationResponse;

    /**
     * Refund a completed transaction, fully or partially.
     */
    public function refund(RefundRequest $request): RefundResponse;

    /**
     * Check that a webhook payload genuinely came from this gateway, using
     * whatever mechanism the gateway uses (HMAC signature header, static
     * secret hash comparison, etc.).
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool;

    /**
     * Turn a decoded webhook payload into a normalized WebhookEvent.
     * Call verifyWebhookSignature() first — this does not re-verify.
     *
     * @param array<string, mixed> $payload
     */
    public function parseWebhookPayload(array $payload): WebhookEvent;
}
