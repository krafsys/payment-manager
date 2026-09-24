<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Exceptions;

class InvalidWebhookSignatureException extends PaymentException
{
    public static function forGateway(string $gateway): self
    {
        return new self(sprintf(
            'Webhook signature verification failed for %s. Refusing to process the payload — this request may not be genuine.',
            $gateway
        ));
    }
}
