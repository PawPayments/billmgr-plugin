<?php

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
