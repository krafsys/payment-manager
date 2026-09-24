<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Exceptions;

class GatewayRequestException extends PaymentException
{
    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message,
        public readonly string $gateway,
        public readonly int $statusCode,
        public readonly array $responseBody = []
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    public static function fromResponse(string $gateway, int $statusCode, array $responseBody, string $rawMessage = ''): self
    {
        $message = $rawMessage !== ''
            ? $rawMessage
            : sprintf('%s request failed with HTTP %d.', $gateway, $statusCode);

        return new self($message, $gateway, $statusCode, $responseBody);
    }
}
