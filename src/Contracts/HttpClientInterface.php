<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Contracts;

use Krafsys\PaymentManager\Http\HttpResponse;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $jsonBody
     */
    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): HttpResponse;
}
