<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests\Gateways;

use Krafsys\PaymentManager\Config\GatewayConfig;
use Krafsys\PaymentManager\DTO\PaymentRequest;
use Krafsys\PaymentManager\Enums\TransactionStatus;
use Krafsys\PaymentManager\Gateways\KoraGateway;
use Krafsys\PaymentManager\Tests\Fakes\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class KoraGatewayTest extends TestCase
{
    private function makeGateway(FakeHttpClient $http): KoraGateway
    {
        return new KoraGateway(new GatewayConfig(secretKey: 'sk_test_kora'), $http);
    }

    public function test_initialize_sends_amount_in_major_units_and_returns_checkout_url(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => true,
            'message' => 'Charge initiated',
            'data' => ['reference' => 'krafsys_ref_2', 'checkout_url' => 'https://checkout.korapay.com/xyz'],
        ]);

        $response = $this->makeGateway($http)->initialize(new PaymentRequest(
            amount: 2500.00,
            email: 'buyer@example.com',
            customerName: 'Ada Lovelace',
            reference: 'krafsys_ref_2'
        ));

        self::assertTrue($response->successful);
        self::assertSame('https://checkout.korapay.com/xyz', $response->checkoutUrl);

        $sent = $http->lastRequest();
        // Kora expects amount in the major unit (Naira), unlike Paystack/Flutterwave's kobo.
        self::assertSame(2500.00, $sent['body']['amount']);
        self::assertSame('Ada Lovelace', $sent['body']['customer']['name']);
    }

    public function test_verify_prefers_amount_paid_over_amount(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'status' => true,
            'data' => [
                'status' => 'success',
                'reference' => 'krafsys_ref_2',
                'amount' => 2500,
                'amount_paid' => 2500,
                'currency' => 'NGN',
            ],
        ]);

        $result = $this->makeGateway($http)->verify('krafsys_ref_2');

        self::assertSame(TransactionStatus::Success, $result->status);
        self::assertSame(2500.0, $result->amount);
    }

    public function test_webhook_signature_is_hmac_sha256_of_the_data_payload(): void
    {
        $gateway = $this->makeGateway(new FakeHttpClient());
        $dataPayload = json_encode(['reference' => 'krafsys_ref_2', 'status' => 'success']);
        $validSignature = hash_hmac('sha256', $dataPayload, 'sk_test_kora');

        self::assertTrue($gateway->verifyWebhookSignature($dataPayload, $validSignature));
        self::assertFalse($gateway->verifyWebhookSignature($dataPayload, 'wrong'));
    }
}
