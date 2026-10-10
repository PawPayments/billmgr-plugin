#!/usr/bin/php
<?php

set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmpawpayments");
require_once 'pawpayments_util.php';

// The decisions live in pawpayments_rules.php; this script only does the I/O.

[$rawBody, $signature] = PawPaymentsWebhookRequest();

$target = PawPaymentsResultTarget($rawBody);
if (isset($target['respond'])) {
    PawPaymentsRespond(...$target['respond']);
}
$elid = $target['elid'];

$info = LocalQuery("payment.info", ["elid" => $elid]);
$action = PawPaymentsResultAction($elid, $rawBody, $signature, PawPaymentsPaymentFacts($info));

if (isset($action['setpaid'])) {
    LocalQuery("payment.setpaid", $action['setpaid']);
}
PawPaymentsLog($action['logs']);
PawPaymentsRespond(...$action['respond']);
