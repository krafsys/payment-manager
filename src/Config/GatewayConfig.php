<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Config;

/**
 * Holds whatever credentials a gateway needs. Not every field applies to
 * every gateway (e.g. contractCode is Monnify-only) — each Gateway class
 * only reads the fields it actually needs and validates their presence.
 */
final class GatewayConfig
{
    public function __construct(
        public readonly string $secretKey,
        public readonly ?string $publicKey = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $contractCode = null,
        public readonly ?string $webhookSecret = null,
        public readonly bool $sandbox = false,
        public readonly ?string $baseUrlOverride = null
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            secretKey: (string) ($config['secret_key'] ?? ''),
            publicKey: isset($config['public_key']) ? (string) $config['public_key'] : null,
            apiKey: isset($config['api_key']) ? (string) $config['api_key'] : null,
            contractCode: isset($config['contract_code']) ? (string) $config['contract_code'] : null,
            webhookSecret: isset($config['webhook_secret']) ? (string) $config['webhook_secret'] : null,
            sandbox: (bool) ($config['sandbox'] ?? false),
            baseUrlOverride: isset($config['base_url']) ? (string) $config['base_url'] : null,
        );
    }
}
