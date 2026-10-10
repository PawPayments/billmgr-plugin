<?php

namespace PawPayments\Sdk;

class Webhook
{
    public static function verifyRawBody(
        string $rawBody,
        string $headerSignature,
        string $apiKey
    ): bool {
        // An empty key makes the HMAC computable by anyone, and anything but the
        // 64 hex chars of a SHA-256 digest cannot be a signature.
        if ($apiKey === '' || !preg_match('/\A[0-9a-f]{64}\z/i', $headerSignature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $apiKey);
        return hash_equals($expected, strtolower($headerSignature));
    }

    public static function verify(
        string $rawBody,
        string $headerSignature,
        array $payload,
        string $apiKey
    ): bool {
        return self::verifyRawBody($rawBody, $headerSignature, $apiKey);
    }

    /**
     * Whether a paid (`success` / `paid_over`) webhook pays an amount the
     * integration is about to settle, e.g. an order or invoice total.
     *
     * The gateway has already judged the payment against its own invoice: it
     * sends `success` only when what arrived is within that invoice's underpay
     * tolerance — whichever one the merchant configured, which the webhook does
     * not carry — and `paid_over` when more arrived. What is left for the
     * receiver is to check that the invoice is for this amount: it was issued
     * (`initial_fiat_amount`) for at least $total, in $currency, give or take a
     * cent of rounding. So a genuine webhook for a cheaper invoice — one made
     * with a stolen API key and an `extra` naming this order, say — never
     * settles it, whatever the tolerance.
     *
     * Every paid webhook the gateway sends carries `initial_fiat_amount`; a
     * payload without it is refused.
     */
    public static function coversAmount(array $payload, string $currency, $total): bool
    {
        $paidCurrency = strtoupper((string) ($payload['fiat_currency'] ?? ''));
        if ($paidCurrency === '' || $paidCurrency !== strtoupper($currency)) {
            return false;
        }

        $issued = $payload['initial_fiat_amount'] ?? null;
        if (!is_numeric($issued) || !is_numeric($total)) {
            return false;
        }

        // One cent absorbs rounding of the fiat amounts on either side.
        return (float) $issued >= (float) $total - 0.01;
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
