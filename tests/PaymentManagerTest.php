<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests;

use Krafsys\PaymentManager\Enums\GatewayName;
use Krafsys\PaymentManager\Exceptions\InvalidGatewayException;
use Krafsys\PaymentManager\Gateways\PaystackGateway;
use Krafsys\PaymentManager\PaymentManager;
use PHPUnit\Framework\TestCase;

final class PaymentManagerTest extends TestCase
{
    public function test_it_resolves_a_configured_gateway_by_name(): void
    {
        $manager = new PaymentManager(['paystack' => ['secret_key' => 'sk_test_123']]);

        $gateway = $manager->gateway('paystack');

        self::assertInstanceOf(PaystackGateway::class, $gateway);
        self::assertSame('paystack', $gateway->getName());
    }

    public function test_it_accepts_the_gateway_name_enum_too(): void
    {
        $manager = new PaymentManager(['paystack' => ['secret_key' => 'sk_test_123']]);

        self::assertInstanceOf(PaystackGateway::class, $manager->gateway(GatewayName::Paystack));
    }

    public function test_it_caches_resolved_gateway_instances(): void
    {
        $manager = new PaymentManager(['paystack' => ['secret_key' => 'sk_test_123']]);

        self::assertSame($manager->gateway('paystack'), $manager->gateway('paystack'));
    }

    public function test_it_is_case_insensitive(): void
    {
        $manager = new PaymentManager(['paystack' => ['secret_key' => 'sk_test_123']]);

        self::assertInstanceOf(PaystackGateway::class, $manager->gateway('PayStack'));
    }

    public function test_it_throws_for_an_unknown_gateway(): void
    {
        $manager = new PaymentManager([]);

        $this->expectException(InvalidGatewayException::class);

        $manager->gateway('not-a-real-gateway');
    }

    public function test_it_throws_when_a_known_gateway_has_no_configuration(): void
    {
        $manager = new PaymentManager([]);

        $this->expectException(InvalidGatewayException::class);

        $manager->gateway('paystack');
    }

    public function test_it_lists_available_gateways(): void
    {
        $manager = new PaymentManager([]);

        self::assertSame(
            ['paystack', 'flutterwave', 'monnify', 'kora', 'bachs'],
            $manager->availableGateways()
        );
    }
}
