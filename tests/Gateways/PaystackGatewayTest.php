<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests\Gateways;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\DTO\RefundRequest;
use Krafsys\PaymentManager\Enums\TransactionStatus;
use Krafsys\PaymentManager\Exceptions\InvalidCredentialsException;
use Krafsys\PaymentManager\Gateways\PaystackGateway;
use Krafsys\PaymentManager\Tests\Fakes\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PaystackGatewayTest extends TestCase
{
    private function makeGateway(FakeHttpClient $http): PaystackGateway
    {
        return new PaystackGateway(new GatewayConfig(secretKey: 'sk_test_123'), $http);
    }

    public function test_initialize_sends_amount_in_kobo_and_returns_checkout_url(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'reference' => 'krafsys_ref_1',
            ],
        ]);

        $response = $this->makeGateway($http)->initialize(new PaymentRequest(
            amount: 5000.00,
            email: 'buyer@example.com',
            reference: 'krafsys_ref_1'
        ));

        self::assertTrue($response->successful);
        self::assertSame('https://checkout.paystack.com/abc123', $response->checkoutUrl);
        self::assertSame('krafsys_ref_1', $response->reference);

        $sent = $http->lastRequest();
        self::assertSame(500000, $sent['body']['amount']); // 5000.00 NGN -> 500000 kobo
        self::assertSame('Bearer sk_test_123', $sent['headers']['Authorization']);
    }

    public function test_verify_maps_paystack_status_to_normalized_enum(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'reference' => 'krafsys_ref_1',
                'amount' => 500000,
                'currency' => 'NGN',
                'paid_at' => '2026-09-24T10:00:00.000Z',
            ],
        ]);

        $result = $this->makeGateway($http)->verify('krafsys_ref_1');

        self::assertSame(TransactionStatus::Success, $result->status);
        self::assertTrue($result->isSuccessful());
        self::assertSame(5000.0, $result->amount); // converted back from kobo
    }

    public function test_it_throws_invalid_credentials_exception_on_401(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(401, ['status' => false, 'message' => 'Invalid key']);

        $this->expectException(InvalidCredentialsException::class);

        $this->makeGateway($http)->verify('some-ref');
    }

    public function test_refund_converts_amount_to_kobo(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => true,
            'message' => 'Refund queued',
            'data' => ['id' => 991, 'status' => 'pending'],
        ]);

        $response = $this->makeGateway($http)->refund(new RefundRequest(reference: 'krafsys_ref_1', amount: 1000.00));

        self::assertTrue($response->successful);
        self::assertSame('pending', $response->status);

        $sent = $http->lastRequest();
        self::assertSame(100000, $sent['body']['amount']);
    }

    public function test_webhook_signature_verification(): void
    {
        $gateway = $this->makeGateway(new FakeHttpClient());
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ref_1']]);
        $validSignature = hash_hmac('sha512', $body, 'sk_test_123');

        self::assertTrue($gateway->verifyWebhookSignature($body, $validSignature));
        self::assertFalse($gateway->verifyWebhookSignature($body, 'not-the-right-signature'));
        self::assertFalse($gateway->verifyWebhookSignature($body, null));
    }

    public function test_parse_webhook_payload(): void
    {
        $gateway = $this->makeGateway(new FakeHttpClient());

        $event = $gateway->parseWebhookPayload([
            'event' => 'charge.success',
            'data' => ['reference' => 'ref_1', 'status' => 'success'],
        ]);

        self::assertSame('charge.success', $event->event);
        self::assertSame('ref_1', $event->reference);
        self::assertSame(TransactionStatus::Success, $event->status);
    }
}
