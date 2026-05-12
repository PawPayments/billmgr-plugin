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

    public function createInvoice(array $params): array
    {
        return $this->request('POST', '/api/v2/invoices', $params);
    }

    public function getInvoice(string $orderId): array
    {
        return $this->request('GET', '/api/v2/invoices/' . urlencode($orderId));
    }

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
        $query = empty($params) ? '' : '?' . http_build_query($params);
        return $this->request('GET', '/api/v2/permanent' . $query);
    }

    public function deactivatePermanentAddress(string $addressId): array
    {
        return $this->request('DELETE', '/api/v2/permanent/' . urlencode($addressId));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($method === 'POST' && $body !== null) {
            $json = json_encode($body);
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

        return $decoded['result'] ?? $decoded['data'] ?? $decoded;
    }
}
