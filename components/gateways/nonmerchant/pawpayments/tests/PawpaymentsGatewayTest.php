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

    public function testMapStatusCancelledWithPartialIsApproved(): void
    {
        $this->assertSame('approved', $this->invoke('mapStatus', ['cancelled', '40.00']));
        $this->assertSame('declined', $this->invoke('mapStatus', ['cancelled', '0']));
        $this->assertSame('declined', $this->invoke('mapStatus', ['failed', '40.00']));
    }

    public function testInvoicesCoveredByAppliesThePartialInOrder(): void
    {
        $invoices = [['id' => '101', 'amount' => '30.00'], ['id' => '102', 'amount' => '70.00']];

        $this->assertSame(
            [['id' => '101', 'amount' => 30.0], ['id' => '102', 'amount' => 10.0]],
            $this->invoke('invoicesCoveredBy', [$invoices, 40])
        );
        $this->assertSame([['id' => '101', 'amount' => 25.5]], $this->invoke('invoicesCoveredBy', [$invoices, 25.5]));
    }

    public function testSuccessWithoutSignedInClientReportsNone(): void
    {
        StubSession::$data = [];
        $this->assertNull($this->gateway->success(['client_id' => '42'], [])['client_id']);
    }

    public function testMapStatusUnknownIsIgnored(): void
    {
        $this->assertNull($this->invoke('mapStatus', ['something_else']));
        $this->assertNull($this->invoke('mapStatus', ['']));
    }

    // ─── success() (browser return) ─────────────────────────────────────────

    public function testSuccessIgnoresClientControlledReturnQuery(): void
    {
        $this->gateway->setCurrency('EUR');
        $get = [
            'paw_order_id' => 'deadbeefdeadbeefdeadbeef',
            'client_id' => '42',
            'amount' => '10.00',
            'currency' => 'USD',
            'invoices' => '101=10.00',
        ];

        StubSession::$data = ['blesta_client_id' => '7'];
        $result = $this->gateway->success($get, []);

        // The signed-in client, never the one the query names.
        $this->assertSame(7, $result['client_id']);
        $this->assertSame('pending', $result['status']);
        $this->assertNull($result['amount']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertNull($result['transaction_id']);
        $this->assertSame([], $result['invoices']);
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

    // ─── validate() (the signed webhook) ────────────────────────────────────

    private const KEY = 'blesta_test_key_0123456789';

    /** validate() for a webhook paying invoices 101 (30.00) and 102 (70.00) of client 7. */
    private function webhook(array $payload, string $key = self::KEY)
    {
        $this->gateway->setMeta(['api_key' => self::KEY]);
        // Floats keep their fraction (40.0, not 40), as in the gateway's JSON.
        $body = json_encode($payload + [
            'order_id' => 'po1',
            'metadata' => ['client_id' => '7', 'invoices' => '101=30.00|102=70.00'],
        ], JSON_PRESERVE_ZERO_FRACTION);

        return $this->invoke('handleWebhook', [$body, hash_hmac('sha256', $body, $key)]);
    }

    public function testWebhookWithoutAKeyRecordsNothing(): void
    {
        // An empty HMAC key makes any signature computable.
        $this->gateway->setMeta(['api_key' => '']);
        $body = '{"order_id":"po1","status":"success","fiat_amount":100}';

        $this->assertSame([], $this->invoke('handleWebhook', [$body, hash_hmac('sha256', $body, '')]));
        $this->assertArrayHasKey('gateway', $this->gateway->Input->errors);
    }

    /** @dataProvider badSignatures */
    public function testUnsignedOrWronglySignedWebhookRecordsNothing(string $signature): void
    {
        $this->gateway->setMeta(['api_key' => self::KEY]);
        $body = '{"order_id":"po1","status":"success","fiat_amount":100,"fiat_currency":"USD"}';

        $this->assertSame([], $this->invoke('handleWebhook', [$body, $signature]));
        $this->assertArrayHasKey('signature', $this->gateway->Input->errors);
    }

    public function badSignatures(): array
    {
        $body = '{"order_id":"po1","status":"success","fiat_amount":100,"fiat_currency":"USD"}';

        return [
            'missing' => [''],
            'another key' => [hash_hmac('sha256', $body, 'another_company_key')],
            'another body' => [hash_hmac('sha256', '{"order_id":"po1","status":"failed"}', self::KEY)],
            'not hex' => [str_repeat('q', 64)],
        ];
    }

    public function testEmptyBodyRecordsNothing(): void
    {
        $this->gateway->setMeta(['api_key' => self::KEY]);

        $this->assertSame([], $this->invoke('handleWebhook', ['', hash_hmac('sha256', '', self::KEY)]));
    }

    public function testSignedBodyThatIsNotJsonRecordsNothing(): void
    {
        $this->gateway->setMeta(['api_key' => self::KEY]);

        $this->assertSame([], $this->invoke('handleWebhook', ['nope', hash_hmac('sha256', 'nope', self::KEY)]));
    }

    public function testPermanentAddressDepositRecordsNothing(): void
    {
        $this->assertSame([], $this->webhook(['status' => 'success', 'permanent_address_id' => 'pa1', 'fiat_amount' => 100]));
    }

    public function testPaidWebhookIsApprovedForTheInvoicesItNames(): void
    {
        $result = $this->webhook(['status' => 'success', 'fiat_amount' => 100.0, 'fiat_currency' => 'USD']);

        $this->assertSame('approved', $result['status']);
        $this->assertSame(100.0, $result['amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame(7, $result['client_id']);
        $this->assertSame('po1', $result['transaction_id']);
        $this->assertSame(
            [['id' => '101', 'amount' => '30.00'], ['id' => '102', 'amount' => '70.00']],
            $result['invoices']
        );
    }

    public function testPaidABitUnderRecordsTheIssuedAmount(): void
    {
        // Credited at the live rate, net of commission: a hair under the per-invoice
        // amounts, which Blesta would then refuse to apply in full.
        $result = $this->webhook([
            'status' => 'success', 'fiat_amount' => 99.2, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD',
        ]);

        $this->assertSame(100.0, $result['amount']);
    }

    public function testPaidOverRecordsWhatWasReceived(): void
    {
        $result = $this->webhook([
            'status' => 'paid_over', 'fiat_amount' => 130.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD',
        ]);

        $this->assertSame('approved', $result['status']);
        $this->assertSame(130.0, $result['amount']);
    }

    public function testClientFallsBackToExtraWithoutMetadata(): void
    {
        $this->gateway->setMeta(['api_key' => self::KEY]);
        $body = json_encode(['order_id' => 'po1', 'extra' => '9', 'status' => 'success', 'fiat_amount' => 10.0, 'fiat_currency' => 'USD']);

        $result = $this->invoke('handleWebhook', [$body, hash_hmac('sha256', $body, self::KEY)]);

        $this->assertSame(9, $result['client_id']);
        $this->assertSame([], $result['invoices']);
    }

    public function testMetadataClientWinsOverExtra(): void
    {
        // buildProcess() writes the same client to both; they can only differ for
        // an invoice created some other way, and then metadata is what counts.
        $result = $this->webhook(['status' => 'success', 'extra' => '9', 'fiat_amount' => 100.0, 'fiat_currency' => 'USD']);

        $this->assertSame(7, $result['client_id']);
    }

    public function testCurrencyFallsBackToTheGatewayCurrency(): void
    {
        $this->gateway->setCurrency('EUR');

        $this->assertSame('EUR', $this->webhook(['status' => 'success', 'fiat_amount' => 100.0])['currency']);
    }

    public function testPartialPaymentIsPendingForTheFiatAmountReceived(): void
    {
        // Never `amount`, which is the crypto amount.
        $result = $this->webhook(['status' => 'partially_paid', 'amount' => 0.0004, 'fiat_amount' => 40.0, 'fiat_currency' => 'USD']);

        $this->assertSame('pending', $result['status']);
        $this->assertSame(40.0, $result['amount']);
    }

    public function testExpiredAfterAPartialIsApprovedForThePartialInInvoiceOrder(): void
    {
        $result = $this->webhook(['status' => 'cancelled', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD']);

        $this->assertSame('approved', $result['status']);
        $this->assertSame(40.0, $result['amount']);
        $this->assertSame([['id' => '101', 'amount' => 30.0], ['id' => '102', 'amount' => 10.0]], $result['invoices']);
    }

    /** @dataProvider declinedWebhooks */
    public function testWebhookIsDeclined(array $payload): void
    {
        $this->assertSame('declined', $this->webhook($payload + ['fiat_currency' => 'USD'])['status']);
    }

    public function declinedWebhooks(): array
    {
        return [
            'expired, nothing paid' => [['status' => 'cancelled', 'fiat_amount' => 0]],
            'expired, no amount' => [['status' => 'cancelled']],
            'failed' => [['status' => 'failed', 'fiat_amount' => 100.0]],
            'high risk' => [['status' => 'high_risk', 'fiat_amount' => 100.0]],
        ];
    }

    public function testStatusWithNothingToRecordRecordsNothing(): void
    {
        $this->assertSame([], $this->webhook(['status' => 'expired', 'fiat_amount' => 100.0]));
        $this->assertSame([], $this->webhook(['status' => '']));
    }
}
