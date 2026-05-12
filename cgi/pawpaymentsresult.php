#!/usr/bin/php
<?php

set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmpawpayments");
require_once 'pawpayments_util.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/Webhook.php';

echo "Content-Type: text/html; charset=utf-8\n\n";

$rawBody = ReadRawPostBody();
if (!$rawBody) {
    Error("Empty body in result callback");
    die("Empty body");
}

$payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);

if (!empty($payload['permanent_address_id'])) {
    echo "OK";
    exit;
}

$elid = $payload['extra'] ?? '';
$orderId = $payload['order_id'] ?? '';
$status = $payload['status'] ?? '';

if (!$elid || !$orderId) {
    Error("Missing extra or order_id in result callback");
    die("Missing data");
}

$info = LocalQuery("payment.info", ["elid" => $elid]);
$payment = $info->payment[0];

if (!$payment->id) {
    Error("Payment not found for elid={$elid}");
    die("Payment not found");
}

$secretKey = (string) $payment->paymethod[1]->SECRET_KEY;

$headerSig = $_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '';
if (!$headerSig || !\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $headerSig, $secretKey)) {
    Error("Invalid X-Paw-Signature for elid={$elid}");
    die("Invalid signature");
}

Debug("Result callback: elid={$elid} order_id={$orderId} status={$status}");

switch ($status) {
    case 'success':
    case 'paid_over':
        $currentStatus = (string) ($payment->status_id ?? '');
        if ($currentStatus === '2') {
            Debug("Payment {$elid} already paid, skipping");
            echo "Already paid";
            exit;
        }
        $paymentUrl = $payload['payment_url'] ?? $orderId;
        LocalQuery("payment.setpaid", [
            "elid" => $elid,
            "externalid" => $orderId,
            "info" => $paymentUrl,
        ]);
        Debug("Payment {$elid} marked as paid (order_id={$orderId})");
        break;

    case 'partially_paid':
        Debug("Payment {$elid} partially paid, no action");
        break;

    case 'cancelled':
    case 'failed':
        Debug("Payment {$elid} cancelled/failed");
        break;

    default:
        Debug("Payment {$elid} unknown status: {$status}");
        break;
}

echo "OK";
