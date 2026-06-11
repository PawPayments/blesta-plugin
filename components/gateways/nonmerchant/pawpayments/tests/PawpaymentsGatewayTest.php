<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PawPayments Blesta gateway logic (framework-independent).
 */
class PawpaymentsGatewayTest extends TestCase
{
    /** @var Pawpayments */
    private $gateway;

    protected function setUp(): void
    {
        $this->gateway = new Pawpayments();
    }

    private function invoke($method, array $args = [])
    {
        $ref = new ReflectionMethod(Pawpayments::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->gateway, $args);
    }

    // ─── Invoice (de)serialization ──────────────────────────────────────────

    public function testSerializeInvoicesRoundTrip(): void
    {
        $invoices = [
            ['id' => '101', 'amount' => '10.00'],
            ['id' => '102', 'amount' => '5.50'],
        ];

        $serialized = $this->invoke('serializeInvoices', [$invoices]);
        $this->assertSame('101=10.00|102=5.50', $serialized);

        $back = $this->invoke('unserializeInvoices', [$serialized]);
        $this->assertSame($invoices, $back);
    }

    public function testSerializeEmptyInvoices(): void
    {
        $this->assertSame('', $this->invoke('serializeInvoices', [[]]));
        $this->assertSame([], $this->invoke('unserializeInvoices', ['']));
    }

    public function testUnserializeIgnoresMalformedPairs(): void
    {
        $back = $this->invoke('unserializeInvoices', ['101=10.00|garbage|102=5.50']);
        $this->assertSame([
            ['id' => '101', 'amount' => '10.00'],
            ['id' => '102', 'amount' => '5.50'],
        ], $back);
    }

    // ─── Status mapping ─────────────────────────────────────────────────────

    public function testMapStatusApproved(): void
    {
        $this->assertSame('approved', $this->invoke('mapStatus', ['success']));
        $this->assertSame('approved', $this->invoke('mapStatus', ['paid_over']));
    }

    public function testMapStatusPending(): void
    {
        $this->assertSame('pending', $this->invoke('mapStatus', ['confirming']));
        $this->assertSame('pending', $this->invoke('mapStatus', ['partially_paid']));
    }

    public function testMapStatusDeclined(): void
    {
        $this->assertSame('declined', $this->invoke('mapStatus', ['failed']));
        $this->assertSame('declined', $this->invoke('mapStatus', ['cancelled']));
        $this->assertSame('declined', $this->invoke('mapStatus', ['high_risk']));
    }

    public function testMapStatusUnknownIsIgnored(): void
    {
        $this->assertNull($this->invoke('mapStatus', ['something_else']));
        $this->assertNull($this->invoke('mapStatus', ['']));
    }

    // ─── success() (browser return) ─────────────────────────────────────────

    public function testSuccessParsesReturnQuery(): void
    {
        $get = [
            'paw_order_id' => 'deadbeefdeadbeefdeadbeef',
            'client_id' => '42',
            'amount' => '10.00',
            'currency' => 'USD',
            'invoices' => '101=10.00',
        ];

        $result = $this->gateway->success($get, []);

        $this->assertSame(42, $result['client_id']);
        $this->assertSame('10.00', $result['amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('deadbeefdeadbeefdeadbeef', $result['transaction_id']);
        $this->assertSame([['id' => '101', 'amount' => '10.00']], $result['invoices']);
    }

    // ─── Webhook signature (vendored SDK) ───────────────────────────────────

    public function testWebhookSignatureVerifies(): void
    {
        require_once dirname(__DIR__) . '/vendor/pawpayments/sdk/src/Webhook.php';

        $apiKey = 'test_api_key_abc123';
        $body = '{"order_id":"abc","status":"success","fiat_amount":10.0}';
        $sig = hash_hmac('sha256', $body, $apiKey);

        $this->assertTrue(\PawPayments\Sdk\Webhook::verifyRawBody($body, $sig, $apiKey));
        $this->assertFalse(\PawPayments\Sdk\Webhook::verifyRawBody($body, 'bad', $apiKey));

        // Tampered body must fail against the original signature
        $tampered = '{"order_id":"abc","status":"cancelled","fiat_amount":10.0}';
        $this->assertFalse(\PawPayments\Sdk\Webhook::verifyRawBody($tampered, $sig, $apiKey));
    }
}
