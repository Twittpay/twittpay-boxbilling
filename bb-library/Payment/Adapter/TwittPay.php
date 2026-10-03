<?php

/**
 * TwittPay - BoxBilling payment adapter
 * ---------------------------------------------------------------------------
 * getHtml()             creates the payment and shows the Pay button
 * processTransaction()  runs on the IPN URL, for the webhook and the return
 *
 * Every payment is verified against the API before an invoice is touched, and a
 * transaction id that has already been credited is refused, so a webhook that
 * arrives twice cannot pay the same invoice twice.
 *
 * @version 1.0.0
 */
class Payment_Adapter_TwittPay implements \Box\InjectionAwareInterface
{
    protected $di;
    protected $config = [];

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (empty($config['api_key']) || empty($config['api_url'])) {
            throw new Payment_Exception(
                'TwittPay is not fully configured. Please provide the Endpoint URL and the Brand Key.'
            );
        }

        if (empty($this->config['currency_rate'])) {
            $this->config['currency_rate'] = 120;
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Accept bKash, Nagad, Rocket, Upay and card payments through your own TwittPay gateway.',
            'form' => [
                'api_url' => [
                    'text', [
                        'label'       => 'Endpoint URL:',
                        'description' => 'Your own gateway address, for example https://checkout.twittpay.com',
                    ],
                ],
                'api_key' => [
                    'text', [
                        'label'       => 'Brand Key:',
                        'description' => 'From your gateway dashboard, under Brands.',
                    ],
                ],
                'currency_rate' => [
                    'text', [
                        'label'       => 'USD to BDT Rate:',
                        'description' => 'Used only when the invoice is not already in BDT. 1 USD = this many BDT.',
                        'value'       => '120',
                    ],
                ],
                'auto_redirect' => [
                    'checkbox', [
                        'label' => 'Send the customer straight to the payment page',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id)
    {
        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
        $url     = htmlspecialchars($this->createPayment($invoice), ENT_QUOTES, 'UTF-8');

        $form = '<form action="' . $url . '" method="GET" id="twittpay_form">';
        $form .= '<input type="submit" value="Pay Now" class="bb-button bb-button-submit">';
        $form .= '</form>';

        if (!empty($this->config['auto_redirect'])) {
            $form .= '<script>document.getElementById("twittpay_form").submit();</script>';
        }

        return $form;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $transactionId = $this->transactionIdFrom($data);

        if ($transactionId === '') {
            throw new Payment_Exception('No transaction id received.');
        }

        // The webhook fires again when a pending payment is decided, so a
        // transaction that has already been credited is left alone.
        if ($this->alreadyCredited($transactionId)) {
            throw new Payment_Exception('This payment has already been recorded.');
        }

        $verified = $this->apiCall('/api/payment/verify', ['transaction_id' => $transactionId]);

        // A miss answers status 0, a number. Only a real payment carries text.
        $status = (isset($verified['status']) && is_string($verified['status']))
            ? strtoupper(trim($verified['status']))
            : '';

        if ($status === '') {
            throw new Payment_Exception('The gateway does not know this transaction.');
        }

        if ($status === 'PENDING') {
            // Sent, not approved by the merchant yet. The gateway calls this URL
            // again with the answer, so the invoice is left unpaid for now.
            throw new Payment_Exception('The payment is being checked and will be applied once it clears.');
        }

        if ($status !== 'COMPLETED') {
            throw new Payment_Exception('Payment not completed.');
        }

        $meta = $this->decodeMetadata($verified);

        if (empty($meta['invoiceid'])) {
            throw new Payment_Exception('This payment carries no invoice reference.');
        }

        $invoice     = $this->di['db']->getExistingModelById('Invoice', $meta['invoiceid'], 'Invoice not found');
        $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');

        // Record the invoice's own amount and currency, not the converted BDT.
        $amount   = isset($meta['invoice_amount']) ? $meta['invoice_amount'] : ($verified['amount'] ?? 0);
        $currency = !empty($meta['invoice_currency']) ? $meta['invoice_currency'] : 'BDT';
        $method   = !empty($verified['payment_method']) ? $verified['payment_method'] : 'TwittPay';

        $tx_data = [
            'txn_id'     => $transactionId,
            'amount'     => $amount,
            'currency'   => $currency,
            'txn_status' => $status,
            'type'       => $method,
            'status'     => 'complete',
        ];

        $this->di['mod_service']('Invoice', 'Transaction')->update($transaction, $tx_data);

        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
        $this->di['mod_service']('client')->addFunds(
            $client,
            $amount,
            $method . ' Transaction ID: ' . $transactionId
        );

        $this->di['mod_service']('Invoice')->payInvoiceWithCredits($invoice);

        return true;
    }

    /** Build the payment and hand back the URL the customer has to go to. */
    protected function createPayment($invoice)
    {
        $client   = $invoice['client'];
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? 'BDT')));
        $total    = (float) $invoice['total'];

        $returnUrl = !empty($this->config['return_url']) ? $this->config['return_url'] : '';
        $cancelUrl = !empty($this->config['cancel_url']) ? $this->config['cancel_url'] : $returnUrl;

        $data = [
            'cus_name'    => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
            'cus_email'   => !empty($client['email']) ? $client['email'] : 'default@gmail.com',
            'amount'      => number_format($this->toBdt($total, $currency), 2, '.', ''),
            'success_url' => $returnUrl,
            'cancel_url'  => $cancelUrl,
            'webhook_url' => $this->config['notify_url'],
            'metadata'    => [
                'invoiceid'        => (string) $invoice['id'],
                'invoice_amount'   => number_format($total, 2, '.', ''),
                'invoice_currency' => $currency,
                'source'           => 'boxbilling',
            ],
        ];

        $response = $this->apiCall('/api/payment/create', $data);

        if (!empty($response['status']) && !empty($response['payment_url'])) {
            return $response['payment_url'];
        }

        throw new Payment_Exception('Failed to create payment: ' . ($response['message'] ?? 'unknown error'));
    }

    /**
     * The id can arrive on the URL, in the webhook's form body, or in a JSON body.
     * BoxBilling hands the request over in $data['get'], $data['post'] and
     * $data['http_raw_post_data'].
     */
    protected function transactionIdFrom($data)
    {
        foreach (['get', 'post'] as $bag) {
            if (empty($data[$bag]) || !is_array($data[$bag])) {
                continue;
            }

            foreach (['transactionId', 'transaction_id'] as $key) {
                if (!empty($data[$bag][$key])) {
                    return trim((string) $data[$bag][$key]);
                }
            }
        }

        $raw = !empty($data['http_raw_post_data'])
            ? $data['http_raw_post_data']
            : file_get_contents('php://input');

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /** Has this transaction id already been written to a transaction row? */
    protected function alreadyCredited($transactionId)
    {
        $rows = $this->di['db']->getAll(
            'SELECT id FROM transaction WHERE txn_id = :tx AND status = :st ORDER BY id DESC LIMIT 1',
            [':tx' => $transactionId, ':st' => 'complete']
        );

        return !empty($rows);
    }

    /** metadata comes back from verify as a JSON string. */
    protected function decodeMetadata($verified)
    {
        if (!is_array($verified) || !isset($verified['metadata'])) {
            return [];
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** The gateway charges BDT. Anything else is converted with the set rate. */
    protected function toBdt($amount, $currency)
    {
        if ($currency === 'BDT') {
            return (float) $amount;
        }

        $rate = (float) $this->config['currency_rate'];

        if ($rate <= 0) {
            $rate = 1;
        }

        return (float) $amount * $rate;
    }

    /**
     * Scheme and host of the configured endpoint. Pasting the whole endpoint or a
     * trailing /api still works.
     */
    protected function baseUrl()
    {
        $raw    = rtrim(trim((string) $this->config['api_url']), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }

    /** One POST to the API. JSON in, array out. */
    protected function apiCall($endpoint, $payload)
    {
        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($payload['metadata'])) {
            $payload['metadata'] = (object) $payload['metadata'];
        }

        $curl = curl_init($this->baseUrl() . $endpoint);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'API-KEY: ' . trim((string) $this->config['api_key']),
            ],
        ]);

        $response = curl_exec($curl);
        $error    = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Payment_Exception('Connection error: ' . $error);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new Payment_Exception('The gateway sent back something that is not JSON.');
        }

        return $decoded;
    }
}
