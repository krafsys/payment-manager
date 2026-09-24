<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\Contracts\GatewayInterface;
use Krafsys\PaymentManager\Contracts\HttpClientInterface;
use Krafsys\PaymentManager\Enums\GatewayName;
use Krafsys\PaymentManager\Exceptions\InvalidGatewayException;
use Krafsys\PaymentManager\Gateways\BachsGateway;
use Krafsys\PaymentManager\Gateways\FlutterwaveGateway;
use Krafsys\PaymentManager\Gateways\KoraGateway;
use Krafsys\PaymentManager\Gateways\MonnifyGateway;
use Krafsys\PaymentManager\Gateways\PaystackGateway;
use Krafsys\PaymentManager\Http\CurlHttpClient;

/**
 * Usage:
 *
 *   $manager = new PaymentManager([
 *       'paystack' => ['secret_key' => 'sk_...'],
 *       'flutterwave' => ['secret_key' => 'FLWSECK_...', 'webhook_secret' => '...'],
 *       'monnify' => ['api_key' => 'MK_...', 'secret_key' => '...', 'contract_code' => '...'],
 *       'kora' => ['secret_key' => 'sk_...'],
 *       'bachs' => ['secret_key' => 'sk_...'],
 *   ]);
 *
 *   $manager->gateway('paystack')->initialize($paymentRequest);
 */
final class PaymentManager
{
    /** @var array<string, class-string<GatewayInterface>> */
    private const GATEWAY_CLASSES = [
        'paystack' => PaystackGateway::class,
        'flutterwave' => FlutterwaveGateway::class,
        'monnify' => MonnifyGateway::class,
        'kora' => KoraGateway::class,
        'bachs' => BachsGateway::class,
    ];

    /** @var array<string, GatewayInterface> */
    private array $resolved = [];

    /**
     * @param array<string, array<string, mixed>> $config  Keyed by gateway name, see class docblock.
     */
    public function __construct(
        private readonly array $config,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
    }

    public function gateway(string|GatewayName $name): GatewayInterface
    {
        $name = $name instanceof GatewayName ? $name->value : strtolower($name);

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset(self::GATEWAY_CLASSES[$name])) {
            throw InvalidGatewayException::unknown($name, array_keys(self::GATEWAY_CLASSES));
        }

        if (!isset($this->config[$name])) {
            throw InvalidGatewayException::missingConfig($name);
        }

        $gatewayConfig = GatewayConfig::fromArray($this->config[$name]);
        $http = $this->httpClient ?? new CurlHttpClient(gatewayLabel: $name);
        $class = self::GATEWAY_CLASSES[$name];

        return $this->resolved[$name] = new $class($gatewayConfig, $http);
    }

    /**
     * @return string[]
     */
    public function availableGateways(): array
    {
        return array_keys(self::GATEWAY_CLASSES);
    }
}
