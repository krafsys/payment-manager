<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\DTO;

/**
 * What you build and hand to any gateway's initialize() method.
 * Amount is always in the currency's major unit (e.g. Naira, not kobo) —
 * each gateway adapter handles converting to whatever subunit its API expects.
 */
final class PaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly float $amount,
        public readonly string $email,
        public readonly ?string $reference = null,
        public readonly string $currency = 'NGN',
        public readonly ?string $customerName = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $description = null,
        public readonly array $metadata = []
    ) {
    }

    public function referenceOrGenerated(): string
    {
        return $this->reference ?? self::generateReference();
    }

    public static function generateReference(string $prefix = 'krafsys'): string
    {
        return sprintf('%s_%s_%s', $prefix, (string) time(), bin2hex(random_bytes(6)));
    }
}
