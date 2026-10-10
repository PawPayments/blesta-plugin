<?php
/**
 * PawPayments Gateway
 *
 * Accept cryptocurrency payments in Blesta via PawPayments. Customers are
 * redirected to the hosted PawPayments paywall to choose an asset and network;
 * invoices are reconciled automatically once the on-chain payment confirms and
 * PawPayments delivers a signed webhook to Blesta's gateway callback URL.
 *
 * Flow:
 *  - buildProcess(): POST /api/v2/invoices, redirect the client to payment_url.
 *  - validate():     server-to-server webhook (callback/gw/{company}/pawpayments/),
 *                    authenticated by the X-Paw-Signature HMAC header.
 *  - success():      client returns to Blesta; informational only (pending, no
 *                    amount), since its query string is client-controlled.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.pawpayments
 * @author PawPayments
 * @copyright Copyright (c) 2026, PawPayments
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link https://pawpayments.com PawPayments
 */
class Pawpayments extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * Construct a new non-merchant gateway.
     */
    public function __construct()
    {
        // Load configuration required by this gateway
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load models required by this gateway
        Loader::loadModels($this, ['Clients']);

        // Load the language required by this gateway
        Language::loadLang('pawpayments', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * {@inheritdoc}
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView(
            'settings',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'api_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Pawpayments.!error.api_key.empty', true),
                ],
            ],
            'api_base_url' => [
                'valid' => [
                    'rule' => function ($url) {
                        return $url === '' || filter_var($url, FILTER_VALIDATE_URL) !== false;
                    },
                    'message' => Language::_('Pawpayments.!error.api_base_url.valid', true),
                ],
            ],
            'ttl' => [
                'valid' => [
                    'rule' => function ($ttl) {
                        return $ttl === '' || (ctype_digit((string) $ttl) && (int) $ttl >= 300 && (int) $ttl <= 86400);
                    },
                    'message' => Language::_('Pawpayments.!error.ttl.valid', true),
                ],
            ],
        ];

        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function encryptableFields()
    {
        return ['api_key'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        Loader::load(dirname(__FILE__) . DS . 'init.php');

        $this->view = $this->makeView(
            'process',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Form', 'Html']);

        $client_id = $contact_info['client_id'] ?? null;
        $currency = $this->currency ?? null;
        $amount = round((float) $amount, 2);
        $invoices = $this->serializeInvoices($invoice_amounts ?? []);

        // Callback (webhook) URL handled by validate(); return URL handled by success()
        $callback_url = Configure::get('Blesta.gw_callback_url')
            . Configure::get('Blesta.company_id') . '/pawpayments/';
        $return_url = $options['return_url'] ?? '';

        $client = new \PawPayments\Sdk\PawPaymentsClient(
            $this->meta['api_key'] ?? '',
            $this->meta['api_base_url'] ?: 'https://api.pawpayments.com'
        );

        $params = [
            'extra' => (string) $client_id,
            'amount' => $amount,
            'fiat_currency' => $currency,
            // Fixed-price order: STATIC keeps the invoice open after an underpayment so the
            // customer can top it up; VARY (right for balance top-ups) finalises on the
            // first payment, making a shortfall terminal.
            'billing_type' => 'STATIC',
            'ttl' => (int) ($this->meta['ttl'] ?: 3600),
            'notify_url' => $callback_url,
            'on_cancel_url' => $return_url,
            'description' => $options['description'] ?? null,
            'metadata' => [
                'source' => 'blesta',
                'flow' => 'checkout',
                'client_id' => (string) $client_id,
                'company_id' => (string) Configure::get('Blesta.company_id'),
                'invoices' => $invoices,
            ],
        ];

        try {
            $this->log($callback_url, serialize($params), 'input', true);
            $data = $client->createInvoice($params);
            $this->log($callback_url, serialize($data), 'output', !empty($data['payment_url']));
        } catch (\PawPayments\Sdk\Exception\PawPaymentsApiException $e) {
            $this->log($callback_url, $e->getMessage(), 'output', false);
            $this->Input->setErrors(
                ['transaction' => ['response' => Language::_('Pawpayments.!error.create.failed', true)]]
            );

            return null;
        }

        $payment_url = $data['payment_url'] ?? '';
        if (!$payment_url) {
            $this->Input->setErrors(
                ['transaction' => ['response' => Language::_('Pawpayments.!error.create.failed', true)]]
            );

            return null;
        }

        $this->view->set('payment_url', $payment_url);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $get, array $post)
    {
        return $this->handleWebhook(
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '')
        );
    }

    /**
     * validate() for one webhook request: the transaction Blesta records, or
     * [] — with an HTTP status and input errors where it is a refusal — when
     * there is nothing to record. Separate from validate() so the rules can be
     * exercised with a body of the tests' choosing.
     *
     * @param string $raw_body The request body
     * @param string $signature The X-Paw-Signature header
     * @return array See validate()
     */
    private function handleWebhook($raw_body, $signature)
    {
        $api_key = (string) ($this->meta['api_key'] ?? '');
        if ($api_key === '') {
            header('HTTP/1.1 503 Service Unavailable');
            $this->Input->setErrors(
                ['gateway' => ['not_configured' => Language::_('Pawpayments.!error.not_configured', true)]]
            );

            return [];
        }

        Loader::load(dirname(__FILE__) . DS . 'init.php');

        $this->log($_SERVER['REQUEST_URI'] ?? 'pawpayments', $raw_body, 'output', true);

        // Reject anything that does not carry a valid HMAC signature
        if ($raw_body === ''
            || $signature === ''
            || !\PawPayments\Sdk\Webhook::verifyRawBody($raw_body, $signature, $api_key)
        ) {
            header('HTTP/1.1 401 Unauthorized');
            $this->Input->setErrors(
                ['signature' => ['invalid' => Language::_('Pawpayments.!error.signature.invalid', true)]]
            );

            return [];
        }

        try {
            $payload = \PawPayments\Sdk\Webhook::parsePayload($raw_body);
        } catch (\Exception $e) {
            return [];
        }

        // Permanent-address deposits are not bound to a Blesta invoice; ack only
        if (!empty($payload['permanent_address_id'])) {
            return [];
        }

        $metadata = (array) ($payload['metadata'] ?? []);
        $client_id = (int) ($metadata['client_id'] ?? $payload['extra'] ?? 0);
        $invoices = $this->unserializeInvoices($metadata['invoices'] ?? '');
        $gateway_status = (string) ($payload['status'] ?? '');
        $status = $this->mapStatus($gateway_status, $payload['fiat_amount'] ?? null);

        if ($status === null) {
            // Non-terminal/ignored status (e.g. nothing actionable yet)
            return [];
        }

        if ($gateway_status === 'cancelled' && $status === 'approved') {
            // An expired STATIC invoice the client paid in part: the merchant
            // keeps the partial, so the transaction recorded as pending for it
            // is approved for that amount and applied to the invoices in order.
            $amount = (float) $payload['fiat_amount'];
            $invoices = $this->invoicesCoveredBy($invoices, $amount);
        } elseif ($status === 'approved') {
            // `fiat_amount` is what the deposit was worth when credited (live
            // rate, net of commission); on a settled invoice it can land a
            // fraction under the per-invoice amounts in `invoices`, which Blesta
            // then refuses to apply in full.
            $amount = max((float) ($payload['fiat_amount'] ?? 0),
                          (float) ($payload['initial_fiat_amount'] ?? 0));
        } else {
            // A still-open partial keeps the real amount received (never
            // `amount`, which is the crypto amount).
            $amount = $payload['fiat_amount'] ?? null;
        }

        return [
            'client_id' => $client_id ?: null,
            'amount' => $amount,
            'currency' => $payload['fiat_currency'] ?? ($this->currency ?? null),
            'invoices' => $invoices,
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $payload['order_id'] ?? null,
            'parent_transaction_id' => null,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * The browser return proves nothing: anyone can open this URL with any
     * query string. Report it as pending with no amount, invoices or
     * transaction id, so nothing can be recorded or applied from it (nor a
     * transaction id pre-claimed for another client), and for the client
     * signed in to Blesta rather than one named in the query; the signed
     * webhook handled by validate() is the only path that settles invoices.
     */
    public function success(array $get, array $post)
    {
        // Never the client named in the query: the one signed in, if any.
        Loader::loadComponents($this, ['Session']);
        $client_id = (int) $this->Session->read('blesta_client_id');

        return [
            'client_id' => $client_id ?: null,
            'amount' => null,
            'currency' => $this->currency ?? null,
            'invoices' => [],
            'status' => 'pending',
            'transaction_id' => null,
            'parent_transaction_id' => null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function capture($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * {@inheritdoc}
     */
    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Maps a PawPayments invoice status to a Blesta transaction status.
     *
     * @param string $status The PawPayments webhook status
     * @param mixed $fiat_amount The webhook `fiat_amount` (value received)
     * @return string|null A Blesta transaction status, or null to ignore the event
     */
    private function mapStatus($status, $fiat_amount = null)
    {
        switch ($status) {
            case 'success':
            case 'paid_over':
                return 'approved';
            case 'confirming':
            case 'partially_paid':
                return 'pending';
            case 'cancelled':
                // `cancelled` is an expired STATIC invoice after a partial
                // payment the merchant keeps; without one there is nothing.
                return is_numeric($fiat_amount) && (float) $fiat_amount > 0 ? 'approved' : 'declined';
            case 'failed':
            case 'high_risk':
                return 'declined';
            default:
                return null;
        }
    }

    /**
     * The invoices an amount pays, in order, each for at most its own amount.
     *
     * @param array $invoices A numerically indexed array of invoices (id, amount)
     * @param float $amount The amount to apply
     * @return array The invoices with the amounts that $amount covers
     */
    private function invoicesCoveredBy(array $invoices, $amount)
    {
        $covered = [];
        $remaining = round((float) $amount, 2);
        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }
            $applied = min($remaining, round((float) $invoice['amount'], 2));
            $covered[] = ['id' => $invoice['id'], 'amount' => $applied];
            $remaining = round($remaining - $applied, 2);
        }

        return $covered;
    }

    /**
     * Serializes an array of invoice info into a string.
     *
     * @param array $invoices A numerically indexed array of invoices including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string in the format key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array.
     *
     * @param string $str A serialized string in the format key1=value1|key2=value2
     * @return array A numerically indexed array of invoices including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        foreach (explode('|', (string) $str) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) != 2) {
                continue;
            }
            $invoices[] = ['id' => $parts[0], 'amount' => $parts[1]];
        }

        return $invoices;
    }
}
