#!/usr/bin/php
<?php

// Legacy: receives webhooks for top-up invoices issued by the removed
// pawpaymentstopup.php page (plugin <= 2.0.3). Top-ups now go through the
// standard BillManager flow (Payments -> Add funds -> PawPayments), which is a
// regular payment handled by pawpaymentsresult.php. A paid one is only logged
// for a manual credit. Remove this endpoint once no such invoice can be paid.

set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmpawpayments");
require_once 'pawpayments_util.php';

// The decisions live in pawpayments_rules.php; this script only does the I/O.

[$rawBody, $signature] = PawPaymentsWebhookRequest();

$action = PawPaymentsTopupAction($rawBody, $signature, PawPaymentsMethods());

PawPaymentsLog($action['logs']);
PawPaymentsRespond(...$action['respond']);
