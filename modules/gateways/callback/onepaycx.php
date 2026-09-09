<?php
/**
 * 1pay.cx for WHMCS — callback endpoint.
 *
 * Served at https://<whmcs>/modules/gateways/callback/onepaycx.php and used in
 * two ways:
 *
 *  1. Signed notify (POST, no `action`): 1pay.cx sends { event, data, at } with
 *     `X-1pay-Signature` = hex HMAC-SHA256 of the RAW body under the merchant's
 *     webhook secret. On `payment.paid` the invoice named by `data.reference` is
 *     credited. Deliveries are at-least-once, so a repeat is a harmless no-op.
 *
 *  2. Buyer return (GET, `action=return&invoiceid=N`): the hosted checkout sends
 *     the buyer back here after paying. If the notify has not landed yet, the
 *     open payment(s) this module created for that invoice (recorded in
 *     mod_onepaycx_payments) are polled via GET /v1/payments/{id}
 *     (authoritative, server-to-server) and the invoice credited from that,
 *     then the buyer is redirected to the invoice. Nothing is ever credited
 *     from the merchant's payment list: a payment the module did not create is
 *     never applied to an invoice.
 *
 * Crediting rule: an invoice is credited with the amount THIS module asked
 * 1pay.cx to charge (the mod_onepaycx_payments row), never the API's `amount`,
 * which includes any customer surcharge the merchant configured on 1pay.cx.
 * The API row's currency must match what was recorded; on a mismatch nothing
 * is credited. Crediting is serialised through the row's `crediting` status so
 * a notify and a return trip racing for the same payment cannot double-credit.
 *
 * All traffic goes to 1pay.cx / api.1pay.cx only. Neither the API key nor the
 * webhook secret is ever logged.
 *
 * @copyright 1pay.cx
 * @license   MIT
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../onepaycx/lib/bootstrap.php';

/* ------------------------------------------------------------------ helpers */

/** Terminate with a plain-text status; used for the machine-facing notify. */
function onepaycx_cb_respond($status, $text)
{
    if (!headers_sent()) {
        http_response_code((int) $status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $text;
    exit;
}

/** Send the buyer somewhere on this WHMCS install. */
function onepaycx_cb_redirect($url)
{
    if (!headers_sent()) {
        header('Cache-Control: no-store');
        header('Location: ' . $url, true, 302);
    }
    echo 'Redirecting to ' . htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

/**
 * The raw request body, byte for byte — the signature is computed over exactly
 * this. ONEPAYCX_INPUT_STREAM lets the test harness substitute a file for
 * php://input (which is empty on the CLI).
 */
function onepaycx_cb_raw_body()
{
    $stream = defined('ONEPAYCX_INPUT_STREAM') ? ONEPAYCX_INPUT_STREAM : 'php://input';
    $body = @file_get_contents($stream);
    return $body === false ? '' : $body;
}

/** The X-1pay-Signature header, lower-cased hex or ''. */
function onepaycx_cb_signature()
{
    $sig = '';
    if (isset($_SERVER['HTTP_X_1PAY_SIGNATURE'])) {
        $sig = (string) $_SERVER['HTTP_X_1PAY_SIGNATURE'];
    } elseif (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-1pay-signature') {
                $sig = (string) $value;
                break;
            }
        }
    }
    $sig = strtolower(trim($sig));
    return ($sig !== '' && ctype_xdigit($sig)) ? $sig : '';
}

/** Constant-time comparison of the expected HMAC with the presented one. */
function onepaycx_cb_signature_ok($rawBody, $signature, $secret)
{
    if ($secret === '' || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signature);
}

/** Two-decimal string, e.g. "10.00". */
function onepaycx_cb_money($amount)
{
    return number_format((float) $amount, 2, '.', '');
}

/**
 * The ISO code the invoice is expected to be paid in: the gateway's "Convert To"
 * currency when set, else the client's own currency. '' when it cannot be
 * determined (then no currency check is possible).
 */
function onepaycx_cb_expected_currency(array $gw, $invoiceId)
{
    $currencyId = !empty($gw['convertto']) ? (int) $gw['convertto'] : OnePayCx_Whmcs_Store::invoiceCurrencyId($invoiceId);
    return $currencyId > 0 ? OnePayCx_Whmcs_Store::currencyCode($currencyId) : '';
}

/**
 * The amount to credit to the invoice. WHMCS may charge invoices in a
 * different currency than the client's when the gateway has "Convert To" set;
 * in that case the paid amount is converted back into the invoice currency.
 * The conversion uses the currency the payment was actually created in
 * ($currency, from our own record) so a "Convert To" change between creating
 * and paying still converts from the right currency; the current setting is
 * only the fallback when that code is unknown to WHMCS.
 */
function onepaycx_cb_invoice_amount(array $gw, $invoiceId, $amount, $currency = '')
{
    $amount = onepaycx_cb_money($amount);
    if (!function_exists('convertCurrency')) {
        return $amount;
    }
    try {
        $paidIn = OnePayCx_Whmcs_Store::currencyIdByCode($currency);
        if ($paidIn <= 0 && !empty($gw['convertto'])) {
            $paidIn = (int) $gw['convertto'];
        }
        if ($paidIn <= 0) {
            return $amount;
        }
        $clientCurrency = OnePayCx_Whmcs_Store::invoiceCurrencyId($invoiceId);
        if ($clientCurrency > 0 && $clientCurrency !== $paidIn) {
            $converted = convertCurrency((float) $amount, $paidIn, $clientCurrency);
            if (is_numeric($converted) && (float) $converted > 0) {
                return onepaycx_cb_money($converted);
            }
        }
    } catch (\Throwable $e) {
        // fall through: credit the raw amount
    }
    return $amount;
}

/**
 * Credit a paid 1pay.cx payment to an invoice, idempotently.
 *
 * $api is what 1pay.cx says about the payment (from the signed notify or
 * GET /v1/payments/{id}): ['amount' => float, 'currency' => string ('' when the
 * source did not carry it)]. It is only trusted for *whether* the payment is
 * paid and in which currency; the amount credited is the one this module
 * recorded when it created the payment (see the file header). Without a local
 * record (table unavailable, or the payment predates it) the API amount is
 * used, after checking its currency against the invoice's expected currency.
 *
 * @param string $reason set when 'refused'
 * @return string 'credited' | 'duplicate' | 'refused'
 */
function onepaycx_cb_credit(array $gw, $invoiceId, $paymentId, array $api, array $logPayload, &$reason = '')
{
    $reason = '';
    // Dies with a message if the invoice does not exist.
    $invoiceId = checkCbInvoiceID((int) $invoiceId, $gw['name']);

    $apiAmount = isset($api['amount']) && is_numeric($api['amount']) ? (float) $api['amount'] : 0.0;
    $apiCurrency = isset($api['currency']) ? strtoupper(trim((string) $api['currency'])) : '';

    $local = OnePayCx_Whmcs_Store::findByPaymentId($paymentId);
    if ($local !== null) {
        if ($local['invoice_id'] !== (int) $invoiceId) {
            $reason = 'invoice mismatch';
        } elseif ($apiCurrency !== '' && $apiCurrency !== $local['currency']) {
            $reason = 'currency mismatch';
        }
        // What we asked to be charged. The API figure may be higher (customer
        // surcharge); it is never credited. Lower would mean a short payment, so
        // never credit more than was actually paid either.
        $amount = $local['amount'];
        if ($apiAmount > 0 && $apiAmount < (float) $amount) {
            $amount = onepaycx_cb_money($apiAmount);
        }
        $currency = $local['currency'];
    } else {
        $expected = onepaycx_cb_expected_currency($gw, $invoiceId);
        if ($apiAmount <= 0) {
            $reason = 'missing amount';
        } elseif ($apiCurrency !== '' && $expected !== '' && $apiCurrency !== $expected) {
            $reason = 'currency mismatch';
        }
        $amount = onepaycx_cb_money($apiAmount);
        $currency = $apiCurrency !== '' ? $apiCurrency : $expected;
    }
    if ($reason !== '') {
        logTransaction($gw['name'], array('error' => $reason, 'paymentId' => $paymentId) + $logPayload, 'Unsuccessful');
        return 'refused';
    }

    // At-least-once delivery: a payment we already recorded is a clean no-op.
    if (OnePayCx_Whmcs_Store::transactionExists($paymentId) === true) {
        return 'duplicate';
    }
    // WHMCS's own guard (dies if the transaction id was already recorded).
    checkCbTransID($paymentId);
    // Atomic claim on our own row: of two concurrent requests for the same
    // payment exactly one gets true. null = no row to lock; the checks above
    // are then all we have (WHMCS's guard is check-then-act too).
    $claimed = OnePayCx_Whmcs_Store::claimForCredit($paymentId);
    if ($claimed === false) {
        return 'duplicate';
    }

    $credit = onepaycx_cb_invoice_amount($gw, $invoiceId, $amount, $currency);
    try {
        logTransaction($gw['name'], $logPayload, 'Successful');
        addInvoicePayment($invoiceId, $paymentId, $credit, '0.00', ONEPAYCX_GATEWAY);
    } catch (\Throwable $e) {
        if ($claimed === true) {
            OnePayCx_Whmcs_Store::markStatus($paymentId, 'pending'); // release the lock; retry later
        }
        throw $e;
    }
    OnePayCx_Whmcs_Store::markStatus($paymentId, 'paid');
    return 'credited';
}

/* ------------------------------------------------------------------- notify */

function onepaycx_cb_notify(array $gw)
{
    if (strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) !== 'POST') {
        onepaycx_cb_respond(405, 'method not allowed');
    }

    $apiKey = isset($gw['apiKey']) ? trim((string) $gw['apiKey']) : '';
    if ($apiKey === '') {
        onepaycx_cb_respond(503, 'gateway not configured');
    }

    $raw = onepaycx_cb_raw_body();
    $signature = onepaycx_cb_signature();
    if ($raw === '' || $signature === '') {
        onepaycx_cb_respond(400, 'missing signature');
    }

    $secret = OnePayCx_Whmcs_Store::webhookSecret($apiKey);
    $ok = onepaycx_cb_signature_ok($raw, $signature, $secret);
    if (!$ok) {
        // The merchant may have rotated the secret or changed the API key: refetch
        // once (throttled inside webhookSecret) and try again.
        $fresh = OnePayCx_Whmcs_Store::webhookSecret($apiKey, true);
        $ok = $fresh !== $secret && onepaycx_cb_signature_ok($raw, $signature, $fresh);
    }
    if (!$ok) {
        logTransaction($gw['name'], array('error' => 'invalid signature'), 'Unsuccessful');
        onepaycx_cb_respond(401, 'bad signature');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        onepaycx_cb_respond(400, 'bad payload');
    }
    $event = isset($payload['event']) ? (string) $payload['event'] : '';
    $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

    if ($event !== 'payment.paid') {
        onepaycx_cb_respond(200, 'ignored');
    }

    $paymentId = isset($data['paymentId']) ? (string) $data['paymentId'] : '';
    if (!OnePayCx_Whmcs_Client::isPaymentId($paymentId)) {
        onepaycx_cb_respond(400, 'missing payment id');
    }

    $reference = isset($data['reference']) ? (string) $data['reference'] : '';
    $amount = isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : 0.0;
    $status = isset($data['status']) ? (string) $data['status'] : 'paid';
    $currency = isset($data['currency']) ? (string) $data['currency'] : ''; // the notify normally omits it

    // Older or partial payloads: look the payment up (authoritative, server-to-server).
    if ($reference === '' || $amount <= 0 || $status !== 'paid') {
        $client = new OnePayCx_Whmcs_Client($apiKey);
        $res = $client->getPayment($paymentId);
        if (!$res['ok'] || !is_array($res['data'])) {
            onepaycx_cb_respond(502, 'could not verify payment');
        }
        $reference = isset($res['data']['reference']) ? (string) $res['data']['reference'] : $reference;
        $amount = isset($res['data']['amount']) ? (float) $res['data']['amount'] : $amount;
        $status = isset($res['data']['status']) ? (string) $res['data']['status'] : $status;
        $currency = isset($res['data']['currency']) ? (string) $res['data']['currency'] : $currency;
    }

    if ($status !== 'paid') {
        onepaycx_cb_respond(200, 'ignored: not paid');
    }
    if (!preg_match('/^\d{1,12}$/', $reference)) {
        onepaycx_cb_respond(200, 'ignored: no invoice reference');
    }
    if ($amount <= 0) {
        onepaycx_cb_respond(400, 'missing amount');
    }

    $reason = '';
    $result = onepaycx_cb_credit($gw, (int) $reference, $paymentId, array('amount' => $amount, 'currency' => $currency), $payload, $reason);
    if ($result === 'refused') {
        // Acknowledged so 1pay.cx stops redelivering; the Gateway Log has the reason.
        onepaycx_cb_respond(200, 'ignored: ' . $reason);
    }
    onepaycx_cb_respond(200, $result === 'duplicate' ? 'already processed' : 'ok');
}

/* ------------------------------------------------------------------- return */

function onepaycx_cb_return(array $gw)
{
    $invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;
    // systemurl is always supplied by getGatewayVariables(); the relative fallback
    // still resolves to the WHMCS root from this file's location.
    $systemUrl = (isset($gw['systemurl']) && trim((string) $gw['systemurl']) !== '') ? rtrim((string) $gw['systemurl'], '/') . '/' : '../../../';
    if ($invoiceId <= 0) {
        onepaycx_cb_redirect($systemUrl . 'clientarea.php?action=invoices');
    }
    $invoiceUrl = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

    $apiKey = isset($gw['apiKey']) ? trim((string) $gw['apiKey']) : '';
    if ($apiKey === '') {
        onepaycx_cb_redirect($invoiceUrl);
    }
    // Already credited (normally the signed notify lands before the buyer is
    // back): straight to the invoice, no API round trip.
    if (OnePayCx_Whmcs_Store::paidPaymentId($invoiceId) !== '') {
        onepaycx_cb_redirect($invoiceUrl . '&paymentsuccess=true');
    }

    // Only payments this module created for this invoice are ever polled. With no
    // record (table unavailable, or the invoice was never paid through this
    // gateway) there is nothing to check: the signed notify credits the invoice.
    $candidates = OnePayCx_Whmcs_Store::openPaymentIds($invoiceId);
    if (count($candidates) === 0) {
        onepaycx_cb_redirect($invoiceUrl);
    }
    $client = new OnePayCx_Whmcs_Client($apiKey);

    // Poll: the buyer normally arrives after the checkout showed "paid", so the
    // first read is usually final; allow a couple of short retries for the gap
    // between the settlement layer confirming and the API row flipping.
    $sawFailure = false;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $pending = false;
        foreach ($candidates as $paymentId) {
            $res = $client->getPayment($paymentId);
            if (!$res['ok'] || !is_array($res['data'])) {
                continue;
            }
            $row = $res['data'];
            if (!isset($row['reference']) || (string) $row['reference'] !== (string) $invoiceId) {
                continue; // not this invoice's payment; never credit it here
            }
            $status = isset($row['status']) ? (string) $row['status'] : 'pending';
            if ($status === 'paid') {
                $api = array(
                    'amount'   => isset($row['amount']) ? $row['amount'] : 0,
                    'currency' => isset($row['currency']) ? $row['currency'] : '',
                );
                $reason = '';
                $result = onepaycx_cb_credit($gw, $invoiceId, $paymentId, $api, array('source' => 'return-poll', 'payment' => $row), $reason);
                if ($result !== 'refused') {
                    onepaycx_cb_redirect($invoiceUrl . '&paymentsuccess=true');
                }
                // Refused (logged): try the next candidate, if any.
            } elseif ($status === 'pending') {
                $pending = true;
            } else {
                OnePayCx_Whmcs_Store::markStatus($paymentId, $status);
                $sawFailure = true;
            }
        }
        if (!$pending || $attempt === 2) {
            break;
        }
        sleep(1);
    }

    onepaycx_cb_redirect($sawFailure && !$pending ? $invoiceUrl . '&paymentfailed=true' : $invoiceUrl);
}

/* --------------------------------------------------------------------- main */

$gatewayParams = getGatewayVariables(ONEPAYCX_GATEWAY);
if (!is_array($gatewayParams) || empty($gatewayParams['type'])) {
    onepaycx_cb_respond(503, 'Module Not Activated');
}
if (!isset($gatewayParams['name']) || $gatewayParams['name'] === '') {
    $gatewayParams['name'] = '1pay.cx';
}

$onepaycxAction = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($onepaycxAction === 'return') {
    onepaycx_cb_return($gatewayParams);
}
onepaycx_cb_notify($gatewayParams);
