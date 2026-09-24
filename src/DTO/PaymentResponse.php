<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\DTO;

final class PaymentResponse
{
    /**
     * @param array<string, mixed> $raw  The untouched, decoded response body from the gateway —
     *                                   escape hatch for anything this DTO doesn't surface.
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $gateway,
        public readonly string $reference,
        public readonly ?string $checkoutUrl,
        public readonly string $message,
        public readonly array $raw = []
    ) {
    }
}
