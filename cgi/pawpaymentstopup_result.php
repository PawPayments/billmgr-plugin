#!/usr/bin/php
<?php

set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmpawpayments");
require_once 'pawpayments_util.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/Webhook.php';

echo "Content-Type: text/html; charset=utf-8\n\n";

$rawBody = ReadRawPostBody();
if (!$rawBody) {
    Error("Empty body in topup result callback");
    die("Empty body");
}

$payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);

if (!empty($payload['permanent_address_id'])) {
    echo "OK";
    exit;
}

$status = $payload['status'] ?? '';
if ($status !== 'success' && $status !== 'paid_over') {
    Debug("Topup result: status={$status}, no action");
    echo "OK";
    exit;
}

$accountId = $payload['extra'] ?? '';
$orderId = $payload['order_id'] ?? '';
$fiatAmount = (float) ($payload['fiat_amount'] ?? $payload['amount'] ?? 0);
$fiatCurrency = $payload['fiat_currency'] ?? 'USD';

if (!$accountId || !$orderId) {
    Error("Missing account_id or order_id in topup result");
    die("Missing data");
}

$paymethodInfo = LocalQuery("paymethod", []);
$secretKey = '';
if ($paymethodInfo) {
    foreach ($paymethodInfo->xpath('//elem') as $pm) {
        if ((string) ($pm->module ?? '') === 'pmpawpayments') {
            $pmDetail = LocalQuery("paymethod.edit", ["elid" => (string) $pm->id]);
            $secretKey = (string) ($pmDetail->SECRET_KEY ?? '');
            break;
        }
    }
}

if (!$secretKey) {
    Error("Cannot find payment method config for signature verification");
    die("Config error");
}

$headerSig = $_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '';
if (!$headerSig || !\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $headerSig, $secretKey)) {
    Error("Invalid X-Paw-Signature for topup order_id={$orderId}");
    die("Invalid signature");
}

Debug("Topup result callback: account_id={$accountId} order_id={$orderId} amount={$fiatAmount} {$fiatCurrency}");

$existingPayments = LocalQuery("payment", ["account" => $accountId]);
if ($existingPayments) {
    foreach ($existingPayments->xpath('//elem') as $ep) {
        if ((string) ($ep->externalid ?? '') === $orderId) {
            Debug("Topup {$orderId} already processed, skipping");
            echo "Already processed";
            exit;
        }
    }
}

$result = LocalQuery("payment.add", [
    "account" => $accountId,
    "amount" => (string) $fiatAmount,
    "externalid" => $orderId,
    "note" => "Crypto deposit " . $orderId,
]);

Debug("Topup payment.add result for account={$accountId}: " . ($result ? $result->asXML() : 'null'));

echo "OK";
