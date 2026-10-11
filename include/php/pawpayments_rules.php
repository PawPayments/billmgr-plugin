<?php
/**
 * The money rules of the BillManager module, free of side effects.
 *
 * pawpayments_util.php opens the module log and installs error handlers the
 * moment it is included, and talks to BillManager through mgrctl. Nothing here
 * does either: these functions take the facts and return what to do, so the
 * CGI scripts stay thin and the rules are testable without a BillManager
 * install (see billmgr/tests). Expects __MODULE__ to be defined, as every
 * module script does.
 *
 * A decision is ['respond' => [code, text], 'logs' => [[level, message], ...]]
 * plus, when money moves, 'setpaid' (payment.setpaid params).
 */

require_once __DIR__ . '/vendor/pawpayments/sdk/src/Webhook.php';

// BillManager payment statuses that still await payment: 1 = new, 2 = payment in
// progress, 8 = initiated (where BillManager 6 leaves a payment the client has
// started until the module calls payment.setinpay).
const PAWPAYMENTS_PAYABLE_STATUSES = ['1', '2', '8'];
const PAWPAYMENTS_CREDITED_STATUS = '4';

/** A decision that moves no money. */
function PawPaymentsDecision(int $code, string $text, array $logs = []): array
{
    return ['respond' => [$code, $text], 'logs' => $logs];
}

/** Whether a request can be a PawPayments webhook at all, before any mgrctl call. */
function PawPaymentsLooksLikeWebhook(string $rawBody, string $signature): bool
{
    return $rawBody !== '' && preg_match('/\A[0-9a-f]{64}\z/i', $signature) === 1;
}

/**
 * The BillManager payment a checkout webhook is about: ['elid' => ...], or a
 * decision to answer with. Reads the still unverified body, only to find the
 * payment whose own method holds the key to verify it with.
 */
function PawPaymentsResultTarget(string $rawBody): array
{
    $unverified = json_decode($rawBody, true);
    if (!is_array($unverified)) {
        return PawPaymentsDecision(400, 'Invalid JSON');
    }
    if (!empty($unverified['permanent_address_id'])) {
        // Not bound to a BillManager payment; acknowledged without any action.
        return PawPaymentsDecision(200, 'OK');
    }
    $elid = (string) ($unverified['extra'] ?? '');
    if (!ctype_digit($elid)) {
        return PawPaymentsDecision(400, 'Missing payment id');
    }
    return ['elid' => $elid];
}

/**
 * What the checkout rules need from a payment.info reply, or null when it
 * names no payment. `module` and `secret_key` belong to the method the payment
 * was created with — pawpaymentspayment.php signs the invoice with that key,
 * so with several PawPayments methods each payment is verified with its own.
 *
 * @param SimpleXMLElement|null|false $info
 */
function PawPaymentsPaymentFacts($info): ?array
{
    $payment = $info ? $info->payment[0] : null;
    if (!$payment || !(string) $payment->id) {
        return null;
    }
    $paymethod = $payment->paymethod[1];

    return [
        'module' => (string) ($paymethod->module ?? ''),
        'secret_key' => (string) ($paymethod->SECRET_KEY ?? ''),
        'status' => (string) $payment->status,
        'externalid' => (string) $payment->externalid,
        'currency' => (string) ($payment->currency[1]->iso ?? ''),
        'amount' => (string) $payment->paymethodamount,
    ];
}

/**
 * What the checkout webhook for payment $elid does.
 *
 * @param array|null $payment PawPaymentsPaymentFacts() of that payment
 */
function PawPaymentsResultAction(string $elid, string $rawBody, string $signature, ?array $payment): array
{
    if ($payment === null) {
        return PawPaymentsDecision(404, 'Payment not found', [['error', "Payment not found for elid={$elid}"]]);
    }
    // A payment of another module has no key of ours to verify with.
    if ($payment['module'] !== __MODULE__) {
        return PawPaymentsDecision(401, 'Invalid signature', [['error', "Payment {$elid} is not a " . __MODULE__ . " payment"]]);
    }
    // An empty HMAC key makes any signature computable; 503 so the gateway
    // retries once the method is configured.
    if ($payment['secret_key'] === '') {
        return PawPaymentsDecision(503, 'Gateway not configured', [['error', 'PawPayments payment method has no API key configured']]);
    }
    if (!\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $signature, $payment['secret_key'])) {
        return PawPaymentsDecision(401, 'Invalid signature', [['error', "Invalid X-Paw-Signature in result callback for payment {$elid}"]]);
    }

    $payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);
    $orderId = (string) ($payload['order_id'] ?? '');
    $status = (string) ($payload['status'] ?? '');
    if ($orderId === '') {
        return PawPaymentsDecision(400, 'Missing order_id', [['error', 'Missing order_id in result callback']]);
    }

    $logs = [['debug', "Result callback: elid={$elid} order_id={$orderId} status={$status}"]];

    switch ($status) {
        case 'success':
        case 'paid_over':
            if ($payment['status'] === PAWPAYMENTS_CREDITED_STATUS && $payment['externalid'] === $orderId) {
                $logs[] = ['debug', "Payment {$elid} is already credited for order_id={$orderId}, skipping"];
                return PawPaymentsDecision(200, 'Already processed', $logs);
            }
            // Money arrived for a payment that can no longer be paid: credited
            // through another invoice (the client opened the payment page twice
            // and paid both), cancelled, refunded... Only a person can sort it out.
            if (!in_array($payment['status'], PAWPAYMENTS_PAYABLE_STATUSES, true)) {
                $logs[] = ['error', "Payment {$elid} (status {$payment['status']}, externalid {$payment['externalid']}) no longer awaits payment,"
                    . " but invoice {$orderId} was paid: " . ($payload['fiat_amount'] ?? '?') . ' ' . ($payload['fiat_currency'] ?? '?')
                    . '; credit it manually'];
                return PawPaymentsDecision(200, 'Payment no longer awaits payment', $logs);
            }
            // The invoice was issued for paymethodamount in the payment's
            // currency (pawpaymentspayment.php); a webhook for another amount or
            // currency does not pay this payment.
            if (!\PawPayments\Sdk\Webhook::coversAmount($payload, $payment['currency'], $payment['amount'])) {
                $logs[] = ['error', "Payment {$elid}: " . ($payload['fiat_amount'] ?? '?') . ' ' . ($payload['fiat_currency'] ?? '?')
                    . " received does not cover {$payment['amount']} {$payment['currency']} (order_id={$orderId}); not marking paid"];
                return PawPaymentsDecision(409, 'Amount or currency does not match the payment', $logs);
            }
            $logs[] = ['debug', "Payment {$elid} marked as paid (order_id={$orderId})"];
            return [
                'respond' => [200, 'OK'],
                'setpaid' => ['elid' => $elid, 'externalid' => $orderId, 'info' => $payload['payment_url'] ?? $orderId],
                'logs' => $logs,
            ];

        case 'partially_paid':
            $logs[] = ['debug', "Payment {$elid} partially paid, no action"];
            break;

        case 'cancelled':
            // An expired invoice the client paid in part: the merchant keeps the
            // partial, but a BillManager payment can only be paid in full.
            if ((float) ($payload['fiat_amount'] ?? 0) > 0) {
                $logs[] = ['error', "Payment {$elid}: invoice {$orderId} expired after a partial payment of "
                    . $payload['fiat_amount'] . ' ' . ($payload['fiat_currency'] ?? '') . '; credit it manually'];
                break;
            }
            $logs[] = ['debug', "Payment {$elid} cancelled"];
            break;

        case 'failed':
            $logs[] = ['debug', "Payment {$elid} failed"];
            break;

        default:
            $logs[] = ['debug', "Payment {$elid} unknown status: {$status}"];
            break;
    }

    return PawPaymentsDecision(200, 'OK', $logs);
}

/**
 * What the legacy top-up webhook does (invoices issued by the top-up page
 * removed in 2.1.1). It never credits on its own. On BillManager 6 that page
 * could not issue an invoice at all (whoami carries the ids as attributes, the
 * page read an element), payment.add with an account and a bare amount creates
 * no payment there, and a payment list has no externalid to tell a repeated
 * delivery apart, so an automatic credit could go nowhere or twice. A paid
 * invoice is verified and logged as an error for the admin to credit by hand.
 * The invoice was signed with the key of whichever PawPayments method the page
 * used, so the method whose key verifies it is the one it belongs to.
 *
 * @param array $methods PawPaymentsMethods(): [['id', 'secret_key'], ...]
 */
function PawPaymentsTopupAction(string $rawBody, string $signature, array $methods): array
{
    $unverified = json_decode($rawBody, true);
    if (is_array($unverified) && !empty($unverified['permanent_address_id'])) {
        return PawPaymentsDecision(200, 'OK');
    }

    $method = null;
    $anyKey = false;
    foreach ($methods as $candidate) {
        if ($candidate['secret_key'] === '') {
            continue;
        }
        $anyKey = true;
        if (\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $signature, $candidate['secret_key'])) {
            $method = $candidate;
            break;
        }
    }
    if (!$anyKey) {
        return PawPaymentsDecision(503, 'Gateway not configured', [['error', 'PawPayments payment method has no API key configured']]);
    }
    if ($method === null) {
        return PawPaymentsDecision(401, 'Invalid signature', [['error', 'Invalid X-Paw-Signature in topup result callback']]);
    }

    $payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);
    $status = (string) ($payload['status'] ?? '');
    if ($status !== 'success' && $status !== 'paid_over') {
        return PawPaymentsDecision(200, 'OK', [['debug', "Topup result: status={$status}, no action"]]);
    }

    // Only `fiat_amount` is a fiat value; `amount` is the crypto amount.
    $orderId = (string) ($payload['order_id'] ?? '');
    $fiat = ($payload['fiat_amount'] ?? '?') . ' ' . strtoupper((string) ($payload['fiat_currency'] ?? ''));
    $client = (string) ($payload['extra'] ?? '');
    return PawPaymentsDecision(200, 'OK', [['error', "Legacy top-up invoice {$orderId} was paid: {$fiat} for client {$client}"
        . " (PawPayments method {$method['id']}); top-ups are not credited automatically any more, credit it manually"]]);
}
