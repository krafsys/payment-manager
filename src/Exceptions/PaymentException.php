<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Exceptions;

use RuntimeException;

/**
 * Base exception type for every error thrown by this package.
 * Catch this if you just want to handle "something went wrong with a payment"
 * without caring about the specific cause.
 */
class PaymentException extends RuntimeException
{
}
