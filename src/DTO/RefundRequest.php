<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\DTO;

final class RefundRequest
{
    /**
     * @param string $reference   The original transaction reference to refund.
     * @param ?float $amount      Null means "refund the full amount".
     */
    public function __construct(
        public readonly string $reference,
        public readonly ?float $amount = null,
        public readonly ?string $reason = null
    ) {
    }
}
