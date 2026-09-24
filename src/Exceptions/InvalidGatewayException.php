<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Exceptions;

class InvalidGatewayException extends PaymentException
{
    public static function unknown(string $name, array $available): self
    {
        return new self(sprintf(
            'Unknown payment gateway "%s". Available gateways: %s.',
            $name,
            implode(', ', $available)
        ));
    }

    public static function missingConfig(string $name): self
    {
        return new self(sprintf(
            'No configuration found for gateway "%s". Did you forget to pass its credentials?',
            $name
        ));
    }
}
