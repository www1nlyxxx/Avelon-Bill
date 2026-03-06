<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
	die('Module not activated!');
}

$content = json_decode(file_get_contents('php://input'), true);

if (!$content) {
    die("No content in callback!");
}

if ($content["state"] != "payed") {
    die();
}

$invoiceid = $content["extra"];

if (!$invoiceid) {
    die("No extra(invoiceid) in callback!");
}

$signature = $content["signature"];

$id = $content["id"];
$salt = $gatewayParams['salt'];

$hash = sha1($id . ":" . $salt);

if (!hash_equals($hash, $signature)) { //Безопасное сравнение подписи callback
    die("Invalid signature!");
}

$invoiceid = checkCbInvoiceID($invoiceid, $gatewayParams['name']);

checkCbTransID($id);

addInvoicePayment($invoiceid, $id, 0, 0, $gatewayModuleName);