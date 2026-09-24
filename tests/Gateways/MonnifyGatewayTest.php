<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests\Gateways;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\Enums\TransactionStatus;
use Krafsys\PaymentManager\Gateways\MonnifyGateway;
use Krafsys\PaymentManager\Tests\Fakes\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class MonnifyGatewayTest extends TestCase
{
    private function config(): GatewayConfig
    {
        return new GatewayConfig(
            secretKey: 'monnify-secret',
            apiKey: 'MK_TEST_KEY',
            contractCode: '123456789',
            sandbox: true
        );
    }

    public function test_initialize_first_logs_in_then_initializes_the_transaction(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'access-token-abc', 'expiresIn' => 3600],
        ]);
        $http->queueJson(200, [
            'requestSuccessful' => true,
            'responseMessage' => 'success',
            'responseBody' => [
                'paymentReference' => 'krafsys_ref_4',
                'checkoutUrl' => 'https://sandbox.monnify.com/checkout/xyz',
            ],
        ]);

        $response = (new MonnifyGateway($this->config(), $http))->initialize(new PaymentRequest(
            amount: 3000.00,
            email: 'buyer@example.com',
            reference: 'krafsys_ref_4'
        ));

        self::assertTrue($response->successful);
        self::assertSame('https://sandbox.monnify.com/checkout/xyz', $response->checkoutUrl);
        self::assertCount(2, $http->recordedRequests);

        $loginRequest = $http->recordedRequests[0];
        self::assertStringContainsString('/api/v1/auth/login', $loginRequest['url']);
        self::assertStringStartsWith('Basic ', $loginRequest['headers']['Authorization']);

        $initRequest = $http->recordedRequests[1];
        self::assertSame('Bearer access-token-abc', $initRequest['headers']['Authorization']);
        self::assertSame('123456789', $initRequest['body']['contractCode']);
    }

    public function test_the_access_token_is_cached_across_calls(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'access-token-abc', 'expiresIn' => 3600],
        ]);
        $http->queueJson(200, ['requestSuccessful' => true, 'responseBody' => ['paymentReference' => 'r1', 'checkoutUrl' => 'https://x']]);
        $http->queueJson(200, ['requestSuccessful' => true, 'responseBody' => ['paymentReference' => 'r2', 'checkoutUrl' => 'https://y']]);

        $gateway = new MonnifyGateway($this->config(), $http);
        $gateway->initialize(new PaymentRequest(amount: 100, email: 'a@b.com', reference: 'r1'));
        $gateway->initialize(new PaymentRequest(amount: 200, email: 'a@b.com', reference: 'r2'));

        // 1 login + 2 init calls = 3 total, not 4 — the second initialize() should reuse the cached token.
        self::assertCount(3, $http->recordedRequests);
    }

    public function test_verify_maps_paid_status_to_success(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'tok', 'expiresIn' => 3600],
        ]);
        $http->queueJson(200, [
            'requestSuccessful' => true,
            'responseBody' => [
                'paymentReference' => 'krafsys_ref_4',
                'paymentStatus' => 'PAID',
                'amountPaid' => 3000,
                'currencyCode' => 'NGN',
                'paidOn' => '2026-09-24 10:00:00',
            ],
        ]);

        $result = (new MonnifyGateway($this->config(), $http))->verify('krafsys_ref_4');

        self::assertSame(TransactionStatus::Success, $result->status);
        self::assertSame(3000.0, $result->amount);
    }
}
