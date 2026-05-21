#!/usr/bin/php
<?php

set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmpawpayments");
require_once 'pawpayments_util.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/Version.php';
require_once __DIR__ . '/../include/php/vendor/pawpayments/sdk/src/PawPaymentsClient.php';

echo "Content-Type: text/html; charset=utf-8\n\n";

const PAWPAYMENTS_SUPPORTED_FIATS = [
    'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'NZD', 'SGD', 'HKD',
    'NGN', 'KRW', 'ILS', 'RON', 'ARS', 'INR', 'IDR', 'MXN', 'MYR', 'TRY',
    'PLN', 'BRL', 'THB',
];

$param = CgiInput();

$auth = $param['auth'] ?? '';
if (!$auth) {
    die("Not authenticated");
}

$whoami = LocalQuery("whoami", [], $auth);
$accountId = (string) ($whoami->id ?? '');
if (!$accountId) {
    die("Cannot determine account");
}

$paymethodInfo = LocalQuery("paymethod", []);
$secretKey = '';
$baseUrl = 'https://api.pawpayments.com';
if ($paymethodInfo) {
    foreach ($paymethodInfo->xpath('//elem') as $pm) {
        if ((string) ($pm->module ?? '') === 'pmpawpayments') {
            $pmDetail = LocalQuery("paymethod.edit", ["elid" => (string) $pm->id]);
            $secretKey = (string) ($pmDetail->SECRET_KEY ?? '');
            $baseUrl = (string) ($pmDetail->API_BASE_URL ?? '') ?: $baseUrl;
            break;
        }
    }
}

if (!$secretKey) {
    die("Payment method not configured");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($param['amount'])) {
    $amount = (float) ($param['amount'] ?? 0);
    $postedCurrency = strtoupper($param['currency'] ?? '');
    $currency = in_array($postedCurrency, PAWPAYMENTS_SUPPORTED_FIATS, true) ? $postedCurrency : 'USD';

    if ($amount < 1 || $amount > 100000) {
        die("Amount must be between 1 and 100,000");
    }

    $resultUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/mancgi/pawpaymentstopup_result.php';

    $client = new \PawPayments\Sdk\PawPaymentsClient($secretKey, $baseUrl);

    try {
        $data = $client->createInvoice([
            'extra' => $accountId,
            'amount' => $amount,
            'fiat_currency' => $currency,
            'billing_type' => 'VARY',
            'metadata' => [
                'source' => 'billmanager',
                'flow' => 'topup',
                'account_id' => $accountId,
            ],
            'notify_url' => $resultUrl,
        ]);
    } catch (\PawPayments\Sdk\Exception\PawPaymentsApiException $e) {
        Error("Topup API error: " . $e->getMessage());
        die("Payment creation failed: " . htmlspecialchars($e->getMessage()));
    }

    $paymentUrl = $data['payment_url'] ?? '';
    if (!$paymentUrl) {
        die("No payment URL returned");
    }

    echo '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /></head><body>
<form name="payment_redirect" method="GET" action="' . htmlspecialchars($paymentUrl) . '"></form>
<script>document.payment_redirect.submit();</script></body></html>';
    exit;
}

echo '<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8" /></head><body>
<h2>Crypto Deposit</h2>
<form method="POST" action="">
    <input type="hidden" name="auth" value="' . htmlspecialchars($auth) . '">
    <label>Amount: <input type="number" name="amount" min="1" max="100000" step="0.01" required></label><br><br>
    <label>Currency:
        <select name="currency">';
foreach (PAWPAYMENTS_SUPPORTED_FIATS as $fiat) {
    echo '            <option value="' . htmlspecialchars($fiat) . '">' . htmlspecialchars($fiat) . '</option>' . "\n";
}
echo '        </select>
    </label><br><br>
    <button type="submit">Continue to Payment</button>
</form>
</body></html>';
