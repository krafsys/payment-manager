<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Http;

final class HttpResponse
{
    /**
     * @param array<string, mixed> $json  Decoded JSON body (empty array if the body wasn't valid JSON)
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $json
    ) {
    }

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function unauthorized(): bool
    {
        return $this->statusCode === 401 || $this->statusCode === 403;
    }
}
