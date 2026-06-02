<?php

namespace PawPayments\Sdk;

use PawPayments\Sdk\Exception\PawPaymentsApiException;

class PawPaymentsClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.pawpayments.com',
        int $timeout = 30
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    // ─── Catalog ───────────────────────────────────────────────────────────

    public function listAssets(): array
    {
        return $this->request('GET', '/api/v2/assets');
    }

    public function getRates(array $params = []): array
    {
        return $this->request('GET', '/api/v2/rates' . $this->buildQuery($params));
    }

    // ─── Merchant ──────────────────────────────────────────────────────────

    public function getBalance(): array
    {
        return $this->request('GET', '/api/v2/balance');
    }

    /**
     * Unified income/payout/refund ledger feed (paginated).
     * Optional filters: page, per_page, date_from, date_to, type (income|payout|refund).
     *
     * @return array{items: array, pagination: array}
     */
    public function listLedger(array $params = []): array
    {
        return $this->requestList('GET', '/api/v2/ledger' . $this->buildQuery($params));
    }

    // ─── Invoices ──────────────────────────────────────────────────────────

    public function createInvoice(array $params): array
    {
        return $this->request('POST', '/api/v2/invoices', $params);
    }

    public function getInvoice(string $orderId): array
    {
        return $this->request('GET', '/api/v2/invoices/' . urlencode($orderId));
    }

    /**
     * List invoices (paginated). Optional filters: page, per_page, sort, order,
     * date_from, date_to, order_ids (array or string), status, asset.
     *
     * @return array{items: array, pagination: array}
     */
    public function listInvoices(array $params = []): array
    {
        return $this->requestList('GET', '/api/v2/invoices' . $this->buildQuery($params));
    }

    /** Resend the merchant webhook for an invoice. */
    public function notifyInvoice(string $orderId): array
    {
        return $this->request('POST', '/api/v2/invoices/' . urlencode($orderId) . '/notify');
    }

    // ─── Payouts ───────────────────────────────────────────────────────────

    /**
     * Create a single payout.
     *
     * Accepts `address`, `amount`, `fiat_currency`, `asset`, optional `ref` and
     * optional `fee_bearer` (`merchant` | `client`, default `merchant`) to choose
     * who covers the network fee.
     *
     * `x-uniq-id` (UUIDv4) provides idempotency for 2 hours; one is generated
     * automatically when `$uniqId` is omitted.
     */
    public function createPayout(array $params, ?string $uniqId = null): array
    {
        return $this->request('POST', '/api/v2/payouts', $params, [
            'x-uniq-id: ' . ($uniqId ?? $this->uuid4()),
        ]);
    }

    /**
     * Create a batch of up to 200 payouts. Each item accepts the same fields as
     * {@see createPayout()}, including the optional `fee_bearer`. Per-item
     * failures are reported individually in the response.
     *
     * @param array<int, array> $items
     */
    public function createPayoutBatch(array $items, ?string $uniqId = null): array
    {
        return $this->request('POST', '/api/v2/payouts/batch', ['items' => array_values($items)], [
            'x-uniq-id: ' . ($uniqId ?? $this->uuid4()),
        ]);
    }

    public function getPayout(string $payoutId): array
    {
        return $this->request('GET', '/api/v2/payouts/' . urlencode($payoutId));
    }

    /**
     * List payouts (paginated). Same filters as {@see listInvoices()}.
     *
     * @return array{items: array, pagination: array}
     */
    public function listPayouts(array $params = []): array
    {
        return $this->requestList('GET', '/api/v2/payouts' . $this->buildQuery($params));
    }

    // ─── Notifications ─────────────────────────────────────────────────────

    /**
     * List webhook delivery attempts (paginated).
     * Optional filters: page, per_page, invoice_id.
     *
     * @return array{items: array, pagination: array}
     */
    public function listNotifications(array $params = []): array
    {
        return $this->requestList('GET', '/api/v2/notifications' . $this->buildQuery($params));
    }

    /** Send a test webhook to `$url`, or to the merchant's callback URL when omitted. */
    public function testNotification(?string $url = null): array
    {
        return $this->request('POST', '/api/v2/notifications/test', $url !== null ? ['url' => $url] : null);
    }

    // ─── Permanent addresses ───────────────────────────────────────────────

    /**
     * Get-or-create a permanent deposit address.
     * Pass either 'family' or 'asset' (or both).
     */
    public function createPermanentAddress(array $params): array
    {
        return $this->request('POST', '/api/v2/permanent', $params);
    }

    public function getPermanentAddress(string $addressId): array
    {
        return $this->request('GET', '/api/v2/permanent/' . urlencode($addressId));
    }

    public function listPermanentAddresses(array $params = []): array
    {
        return $this->request('GET', '/api/v2/permanent' . $this->buildQuery($params));
    }

    public function deactivatePermanentAddress(string $addressId): array
    {
        return $this->request('DELETE', '/api/v2/permanent/' . urlencode($addressId));
    }

    // ─── Internals ─────────────────────────────────────────────────────────

    /**
     * Build a query string, joining array values with commas (e.g. `order_ids`,
     * `assets`) and serialising booleans as `true`/`false`. Returns '' when empty.
     */
    private function buildQuery(array $params): string
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', $value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $normalized[$key] = $value;
        }

        return empty($normalized) ? '' : '?' . http_build_query($normalized);
    }

    /** Generate an RFC 4122 version 4 UUID. */
    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Perform a request and unwrap the payload (`result` / `data` / raw body).
     *
     * @param array<string>|null $extraHeaders Pre-formatted header lines (e.g. "x-uniq-id: …").
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $decoded = $this->send($method, $path, $body, $extraHeaders);

        return $decoded['result'] ?? $decoded['data'] ?? $decoded;
    }

    /**
     * Perform a request to a paginated endpoint and normalise the envelope to
     * `['items' => [...], 'pagination' => [...]]`.
     *
     * @return array{items: array, pagination: array}
     */
    private function requestList(string $method, string $path): array
    {
        $decoded = $this->send($method, $path);
        $items = $decoded['result'] ?? $decoded['data'] ?? [];

        return [
            'items' => $items,
            'pagination' => $decoded['pagination'] ?? [
                'page' => 1,
                'per_page' => count($items),
                'total' => count($items),
                'pages' => 1,
            ],
        ];
    }

    /**
     * Execute the HTTP call and return the decoded JSON body, throwing
     * {@see PawPaymentsApiException} on transport or API errors.
     *
     * @param array<string> $extraHeaders
     */
    private function send(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $headers = array_merge([
            'x-api-key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . Version::USER_AGENT,
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($method === 'POST') {
            $json = json_encode($body ?? new \stdClass());
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new PawPaymentsApiException(
                'cURL error: ' . $curlError,
                'CURL_ERROR',
                null
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new PawPaymentsApiException(
                'Invalid JSON response',
                'INVALID_RESPONSE',
                $httpCode
            );
        }

        if ($httpCode >= 400 || (isset($decoded['ok']) && $decoded['ok'] === false)) {
            $err = $decoded['error'] ?? null;
            if (is_array($err)) {
                $errorCode = (string) ($err['code'] ?? 'UNKNOWN');
                $errorMsg = (string) ($err['message'] ?? 'API error');
            } else {
                $errorCode = (string) ($decoded['code'] ?? $err ?? 'UNKNOWN');
                $errorMsg = (string) ($decoded['message'] ?? $err ?? 'API error');
            }
            throw new PawPaymentsApiException($errorMsg, $errorCode, $httpCode);
        }

        return $decoded;
    }
}
