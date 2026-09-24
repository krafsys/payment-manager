<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests\Gateways;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\Enums\TransactionStatus;
use Krafsys\PaymentManager\Gateways\BachsGateway;
use Krafsys\PaymentManager\Tests\Fakes\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class BachsGatewayTest extends TestCase
{
    private function makeGateway(FakeHttpClient $http): BachsGateway
    {
        return new BachsGateway(new GatewayConfig(secretKey: 'sk_test_bachs', webhookSecret: 'whsec_test'), $http);
    }

    public function test_initialize_builds_a_single_line_item_checkout_session(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'reference' => 'krafsys_ref_5',
            'checkout_url' => 'https://checkout.bachs.io/session/xyz',
        ]);

        $response = $this->makeGateway($http)->initialize(new PaymentRequest(
            amount: 750.00,
            email: 'buyer@example.com',
            reference: 'krafsys_ref_5',
            description: 'Order #5'
        ));

        self::assertTrue($response->successful);
        self::assertSame('https://checkout.bachs.io/session/xyz', $response->checkoutUrl);

        $sent = $http->lastRequest();
        self::assertSame('Order #5', $sent['body']['line_items'][0]['name']);
        self::assertSame(750.00, $sent['body']['line_items'][0]['unit_amount']);
    }

    public function test_it_tolerates_a_data_wrapped_response_envelope_too(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'data' => [
                'reference' => 'krafsys_ref_6',
                'checkout_url' => 'https://checkout.bachs.io/session/abc',
            ],
        ]);

        $response = $this->makeGateway($http)->initialize(new PaymentRequest(
            amount: 100.00,
            email: 'buyer@example.com',
            reference: 'krafsys_ref_6'
        ));

        self::assertSame('https://checkout.bachs.io/session/abc', $response->checkoutUrl);
    }

    public function test_verify_maps_a_paid_status_to_success(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'reference' => 'krafsys_ref_5',
            'status' => 'paid',
            'amount_paid' => 750,
            'currency' => 'NGN',
        ]);

        $result = $this->makeGateway($http)->verify('krafsys_ref_5');

        self::assertSame(TransactionStatus::Success, $result->status);
    }

    public function test_webhook_signature_verification(): void
    {
        $gateway = $this->makeGateway(new FakeHttpClient());
        $body = '{"type":"collection.succeeded","data":{"reference":"krafsys_ref_5"}}';
        $validSignature = hash_hmac('sha256', $body, 'whsec_test');

        self::assertTrue($gateway->verifyWebhookSignature($body, $validSignature));
        self::assertFalse($gateway->verifyWebhookSignature($body, 'wrong'));
    }
}
