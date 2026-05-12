<?php

namespace PawPayments\Sdk;

class Webhook
{
    public static function verifyRawBody(
        string $rawBody,
        string $headerSignature,
        string $apiKey
    ): bool {
        $expected = hash_hmac('sha256', $rawBody, $apiKey);
        return hash_equals($expected, $headerSignature);
    }

    public static function parsePayload(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid webhook payload: not valid JSON');
        }
        return $data;
    }
}
