<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\DTO;

use Krafsys\PaymentManager\Enums\TransactionStatus;

final class VerificationResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly TransactionStatus $status,
        public readonly string $gateway,
        public readonly string $reference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $paidAt,
        public readonly string $message,
        public readonly array $raw = []
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::Success;
    }
}
