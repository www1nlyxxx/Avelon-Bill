<?php
if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function crystalpay_MetaData()
{
	return array(
		'DisplayName' => 'CrystalPAY.io payment module',
		'APIVersion' => '1.1' // Use API Version 1.1
	);
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function crystalpay_config()
{
	return array(
		// the friendly display name for a payment gateway should be
		// defined here for backwards compatibility
		'FriendlyName' => array(
			'Type' => 'System',
			'Value' => 'CrystalPAY',
		),
		'auth_login' => array(
			'FriendlyName' => 'Логин кассы',
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'Указывается при создании кассы. Не путать с названием кассы!',
		),
		'auth_secret' => array(
			'FriendlyName' => 'Secret (первый ключ) кассы',
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'Выдаётся при создании кассы. Ключи можно сбросить в настройках кассы.',
		),
		'salt' => array(
			'FriendlyName' => 'Salt (второй ключ) кассы',
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'Выдаётся при создании кассы. Ключи можно сбросить в настройках кассы.',
		),
		'lifetime' => array(
			'FriendlyName' => 'Время жизни инвойса в минутах',
			'Type' => 'text',
			'Size' => '25',
			'Default' => '4320',
			'Description' => 'Задаёт время, в течение которого можно совершить платёж. Максимум - 4320 минут.',
		),
	);
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function crystalpay_link($params)
{
	$invoiceid = $params['invoiceid'];
	$description = $params['description'];

	$amount = intval($params['amount']);
	$amount_currency = $params['currency'];

	$payer_details = $params['clientdetails']['email'];

	$auth_login = $params['auth_login'];
	$auth_secret = $params['auth_secret'];
	$lifetime = intval($params['lifetime']);

	$redirect_url = $params['returnurl'];

	$callback_url = $params['systemurl'] . "/modules/gateways/callback/crystalpay.php";

	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL, "https://api.crystalpay.io/v2/invoice/create/");
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["auth_login" => $auth_login, "auth_secret" => $auth_secret, "amount" => $amount, "amount_currency" => $amount_currency, "type" => "purchase", "description" => $description, "redirect_url" => $redirect_url, "callback_url" => $callback_url, "extra" => $invoiceid, "payer_details" => $payer_details, "lifetime" => $lifetime]));

	$result = curl_exec($ch);
	curl_close($ch);

	$resultArray = json_decode($result, true);

	if (!$resultArray) {
		return '<b>No result in response!</b>';
	}

	if ($resultArray["error"]) {
		return '<b>Errors in response: ' . implode("; ", $resultArray["errors"]) . '</b>';
	}

	return '<form method="GET" action="' . $resultArray["url"] . '"><input type="hidden" name="i" value="' . $resultArray["id"] . '"><input type="submit" value="' . $params['langpaynow'] . '"></form>';
}
