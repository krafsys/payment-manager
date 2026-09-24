<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Exceptions;

class InvalidCredentialsException extends PaymentException
{
    public static function forGateway(string $gateway): self
    {
        return new self(sprintf(
            'The %s gateway rejected the supplied API credentials (401 Unauthorized). Check your secret/public key.',
            $gateway
        ));
    }
}
