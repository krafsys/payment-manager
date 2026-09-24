<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Enums;

/**
 * Normalized transaction status, mapped from each gateway's own vocabulary
 * (e.g. Paystack "success", Monnify "PAID", Kora "success") so consuming
 * code never has to branch on gateway-specific strings.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Reversed = 'reversed';
    case Processing = 'processing';
    case Unknown = 'unknown';
}
