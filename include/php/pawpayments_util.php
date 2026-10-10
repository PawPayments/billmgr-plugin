<?php

require_once __DIR__ . '/pawpayments_rules.php';

date_default_timezone_set("UTC");
$log_file = fopen("/usr/local/mgr5/var/" . __MODULE__ . ".log", "a");
$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";

function Debug($str) {
    global $log_file;
    fwrite($log_file, date("M j H:i:s") . " [" . getmypid() . "] " . __MODULE__ . " DEBUG " . $str . "\n");
}

function Error($str) {
    global $log_file;
    fwrite($log_file, date("M j H:i:s") . " [" . getmypid() . "] " . __MODULE__ . " ERROR " . $str . "\n");
}

function tmErrorHandler($errno, $errstr, $errfile, $errline) {
    Error($errno . ": " . $errstr . ". In file: " . $errfile . ". On line: " . $errline);
    return true;
}
set_error_handler("tmErrorHandler");

function tmExceptionHandler($exception) {
    Error($exception->getMessage());
    return true;
}
set_exception_handler("tmExceptionHandler");

function LocalQuery($function, $param, $auth = null) {
    $cmd = "/usr/local/mgr5/sbin/mgrctl -m billmgr -o xml " . escapeshellarg($function) . " ";
    foreach ($param as $key => $value) {
        $cmd .= escapeshellarg($key) . "=" . escapeshellarg($value) . " ";
    }
    if ($auth !== null) {
        $cmd .= " auth=" . escapeshellarg($auth);
    }
    $out = [];
    exec($cmd, $out);
    $out_str = implode("\n", $out);
    return simplexml_load_string($out_str);
}

function CgiInput($skip_auth = false) {
    if (!$skip_auth) {
        $input = $_SERVER["QUERY_STRING"];
    } else {
        if ($_SERVER["REQUEST_METHOD"] === 'POST') {
            $input = file_get_contents("php://stdin", null, null, 0, $_SERVER['CONTENT_LENGTH']);
        } else {
            $input = $_SERVER["QUERY_STRING"];
        }
    }

    $param = [];
    parse_str($input, $param);
    if (!$skip_auth && (empty($param["auth"]))) {
        if (!empty($_COOKIE["billmgrses5"])) {
            $param["auth"] = $_COOKIE["billmgrses5"];
        } elseif (!empty($_SERVER["HTTP_COOKIE"])) {
            foreach (explode("; ", $_SERVER["HTTP_COOKIE"]) as $cookie) {
                $parts = explode("=", $cookie, 2);
                if (count($parts) === 2 && $parts[0] === "billmgrses5") {
                    $param["auth"] = explode(":", $parts[1])[0];
                }
            }
        }
    }

    return $param;
}

/**
 * Settings of every PawPayments payment method (a provider may have one per
 * currency or project, each with its own key), read from their own paymethod
 * records. Only the legacy top-up callback needs this; checkout webhooks use
 * the key of the payment's own method (payment.info).
 */
function PawPaymentsMethods() {
    $methods = [];
    $paymethods = LocalQuery("paymethod", []);
    if (!$paymethods) {
        return $methods;
    }
    foreach ($paymethods->xpath('//elem') as $pm) {
        if ((string) ($pm->module ?? '') !== __MODULE__) {
            continue;
        }
        $detail = LocalQuery("paymethod.edit", ["elid" => (string) $pm->id]);
        $methods[] = [
            'id' => (string) $pm->id,
            'secret_key' => (string) ($detail->SECRET_KEY ?? ''),
            'topup_currency' => strtoupper(trim((string) ($detail->TOPUP_CURRENCY ?? ''))),
        ];
    }
    return $methods;
}

const PAWPAYMENTS_HTTP_REASONS = [
    200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found',
    409 => 'Conflict', 503 => 'Service Unavailable',
];

/** Sends the CGI status header and $text, then stops. */
function PawPaymentsRespond($code, $text) {
    echo "Status: {$code} " . (PAWPAYMENTS_HTTP_REASONS[$code] ?? '') . "\n";
    echo "Content-Type: text/plain; charset=utf-8\n\n";
    die($text);
}

/**
 * Raw body and X-Paw-Signature of a webhook, refusing (400) anything that
 * cannot be one before any mgrctl call: an unauthenticated request must not
 * cost BillManager a subprocess.
 */
function PawPaymentsWebhookRequest() {
    $rawBody = (string) ReadRawPostBody();
    $signature = (string) ($_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '');
    if (!PawPaymentsLooksLikeWebhook($rawBody, $signature)) {
        PawPaymentsRespond(400, "Not a PawPayments webhook");
    }
    return [$rawBody, $signature];
}

/** Writes the `logs` of a pawpayments_rules.php decision to the module log. */
function PawPaymentsLog(array $logs) {
    foreach ($logs as [$level, $message]) {
        $level === 'error' ? Error($message) : Debug($message);
    }
}

/** Externalids of the payments already booked on BillManager account $accountId. */
function PawPaymentsCreditedOrderIds($accountId) {
    $ids = [];
    $existing = LocalQuery("payment", ["account" => $accountId]);
    if ($existing) {
        foreach ($existing->xpath('//elem') as $payment) {
            $ids[] = (string) ($payment->externalid ?? '');
        }
    }
    return $ids;
}

function ReadRawPostBody() {
    if ($_SERVER["REQUEST_METHOD"] !== 'POST') {
        return '';
    }
    $size = $_SERVER["CONTENT_LENGTH"] ?? $_SERVER["HTTP_CONTENT_LENGTH"] ?? 0;
    if ($size > 0 && !feof(STDIN)) {
        return fread(STDIN, $size);
    }
    return '';
}
