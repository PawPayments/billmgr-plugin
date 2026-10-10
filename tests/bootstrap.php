<?php
/**
 * Standalone bootstrap: the BillManager module's rules outside a BillManager
 * install. pawpayments_rules.php has no side effects (pawpayments_util.php,
 * which opens /usr/local/mgr5/var logs and shells out to mgrctl, is never
 * loaded here); it only needs the module name every CGI script defines.
 */

define('__MODULE__', 'pmpawpayments');

require_once dirname(__DIR__) . '/include/php/pawpayments_rules.php';
