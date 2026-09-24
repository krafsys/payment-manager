<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Exceptions;

class GatewayTimeoutException extends PaymentException
{
    public static function forGateway(string $gateway, string $curlError): self
    {
        return new self(sprintf(
            'Request to %s timed out or the connection failed after retries: %s',
            $gateway,
            $curlError
        ));
    }
}
