<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Gateways;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\Contracts\GatewayInterface;
use Krafsys\PaymentManager\Contracts\HttpClientInterface;
use Krafsys\PaymentManager\Exceptions\GatewayRequestException;
use Krafsys\PaymentManager\Exceptions\InvalidCredentialsException;
use Krafsys\PaymentManager\Http\HttpResponse;

abstract class AbstractGateway implements GatewayInterface
{
    public function __construct(
        protected readonly GatewayConfig $config,
        protected readonly HttpClientInterface $http
    ) {
    }

    /**
     * Raises the appropriate typed exception for a failed response.
     * Call this only after confirming !$response->successful().
     */
    protected function failOn(HttpResponse $response, ?string $rawMessage = null): never
    {
        if ($response->unauthorized()) {
            throw InvalidCredentialsException::forGateway($this->getName());
        }

        $message = $rawMessage
            ?? (string) ($response->json['message'] ?? $response->json['error'] ?? '');

        throw GatewayRequestException::fromResponse(
            $this->getName(),
            $response->statusCode,
            $response->json,
            $message
        );
    }

    /**
     * Converts a major-unit amount (e.g. Naira) to the minor unit most
     * Nigerian gateways expect (kobo) — i.e. multiplies by 100 and rounds
     * to avoid floating point drift.
     */
    protected function toMinorUnit(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function fromMinorUnit(int|float $amount): float
    {
        return round(((float) $amount) / 100, 2);
    }

    protected function timingSafeEquals(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }
}
