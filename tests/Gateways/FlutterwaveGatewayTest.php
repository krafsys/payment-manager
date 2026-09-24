<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests\Gateways;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\Enums\TransactionStatus;
use Krafsys\PaymentManager\Gateways\FlutterwaveGateway;
use Krafsys\PaymentManager\Tests\Fakes\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class FlutterwaveGatewayTest extends TestCase
{
    private function makeGateway(FakeHttpClient $http, ?string $webhookSecret = null): FlutterwaveGateway
    {
        return new FlutterwaveGateway(
            new GatewayConfig(secretKey: 'FLWSECK_TEST', webhookSecret: $webhookSecret),
            $http
        );
    }

    public function test_initialize_returns_the_flutterwave_checkout_link(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => 'success',
            'message' => 'Payment link created',
            'data' => ['link' => 'https://checkout.flutterwave.com/pay/xyz'],
        ]);

        $response = $this->makeGateway($http)->initialize(new PaymentRequest(
            amount: 1500.00,
            email: 'buyer@example.com',
            reference: 'krafsys_ref_3'
        ));

        self::assertTrue($response->successful);
        self::assertSame('https://checkout.flutterwave.com/pay/xyz', $response->checkoutUrl);

        $sent = $http->lastRequest();
        // Flutterwave takes amount in major units directly, not kobo.
        self::assertSame(1500.00, $sent['body']['amount']);
        self::assertSame('krafsys_ref_3', $sent['body']['tx_ref']);
    }

    public function test_verify_maps_successful_status(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => 'success',
            'data' => [
                'status' => 'successful',
                'tx_ref' => 'krafsys_ref_3',
                'amount' => 1500,
                'currency' => 'NGN',
                'id' => 778899,
            ],
        ]);

        $result = $this->makeGateway($http)->verify('krafsys_ref_3');

        self::assertSame(TransactionStatus::Success, $result->status);
        self::assertSame(1500.0, $result->amount);
    }

    public function test_webhook_signature_is_a_static_hash_comparison_not_hmac(): void
    {
        $gateway = $this->makeGateway(new FakeHttpClient(), webhookSecret: 'my-configured-hash');

        self::assertTrue($gateway->verifyWebhookSignature('{"any":"body"}', 'my-configured-hash'));
        self::assertFalse($gateway->verifyWebhookSignature('{"any":"body"}', 'wrong-hash'));
    }

    public function test_webhook_signature_fails_closed_when_no_secret_is_configured(): void
    {
        $gateway = $this->makeGateway(new FakeHttpClient(), webhookSecret: null);

        self::assertFalse($gateway->verifyWebhookSignature('{"any":"body"}', 'anything'));
    }
}
