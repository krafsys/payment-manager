<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\DTO;

use Krafsys\PaymentManager\Enums\TransactionStatus;

final class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload  The full, decoded webhook payload as sent by the gateway.
     */
    public function __construct(
        public readonly string $gateway,
        public readonly string $event,
        public readonly ?string $reference,
        public readonly TransactionStatus $status,
        public readonly array $payload
    ) {
    }
}
