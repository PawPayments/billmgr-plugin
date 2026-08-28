#!/usr/bin/php
<?php

set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmpawpayments");
require_once 'pawpayments_util.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/Version.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/PawPaymentsClient.php';

echo "Content-Type: text/html; charset=utf-8\n\n";

$param = CgiInput();

if (empty($param['elid'])) {
    Error("no elid");
    die("no elid");
}

$info = LocalQuery("payment.info", ["elid" => $param["elid"]]);
$payment = $info->payment[0];

$elid = (string) $payment->id;
$amount = (float) $payment->paymethodamount;
$currency = (string) ($payment->currency[1]->iso ?? 'USD');
$accountId = (string) ($payment->account ?? '');

$secretKey = (string) $payment->paymethod[1]->SECRET_KEY;
$baseUrl = (string) ($payment->paymethod[1]->API_BASE_URL ?? '') ?: 'https://api.pawpayments.com';
$ttl = (int) (($payment->paymethod[1]->DEFAULT_TTL ?? '') ?: 3600);

$resultUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/mancgi/pawpaymentsresult.php';

$client = new \PawPayments\Sdk\PawPaymentsClient($secretKey, $baseUrl);

try {
    $data = $client->createInvoice([
        'extra' => $elid,
        'amount' => $amount,
        'fiat_currency' => $currency,
        // Fixed-price order: STATIC keeps the invoice open after an underpayment so the
        // customer can top it up; VARY (right for balance top-ups) finalises on the
        // first payment, making a shortfall terminal.
        'billing_type' => 'STATIC',
        'ttl' => $ttl,
        'metadata' => [
            'source' => 'billmanager',
            'flow' => 'checkout',
            'account_id' => $accountId,
        ],
        'notify_url' => $resultUrl,
    ]);
} catch (\PawPayments\Sdk\Exception\PawPaymentsApiException $e) {
    Error("API error: " . $e->getMessage());
    die("Payment creation failed: " . htmlspecialchars($e->getMessage()));
}

$paymentUrl = $data['payment_url'] ?? '';
if (!$paymentUrl) {
    Error("No payment_url in response");
    die("No payment URL returned");
}

Debug("Redirecting payment {$elid} to {$paymentUrl}");

echo '<html>
<head><meta http-equiv="content-type" content="text/html; charset=utf-8" /></head>
<body>
<form name="payment_redirect" method="GET" action="' . htmlspecialchars($paymentUrl) . '">
</form>
<script>document.payment_redirect.submit();</script>
</body>
</html>';
