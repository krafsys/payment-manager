<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\DTO;

final class RefundResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $gateway,
        public readonly string $reference,
        public readonly ?string $refundReference,
        public readonly string $status,
        public readonly string $message,
        public readonly array $raw = []
    ) {
    }
}
