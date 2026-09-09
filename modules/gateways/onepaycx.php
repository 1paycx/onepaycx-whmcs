<?php
/**
 * 1pay.cx for WHMCS — third-party payment gateway module.
 *
 * Flow: invoice page -> create a payment on 1pay.cx (POST /v1/payments) ->
 * "Pay with 1pay.cx" button sends the buyer to the hosted checkout on
 * https://1pay.cx -> buyer pays by card, Bitcoin or Monero -> 1pay.cx POSTs a
 * signed notify to modules/gateways/callback/onepaycx.php -> the invoice is
 * marked paid. The signed notify is the source of truth; the buyer's return
 * trip additionally polls the payment so the invoice updates even if the
 * notify is delayed.
 *
 * Requires WHMCS 8.x and PHP 8.1+. All traffic goes to 1pay.cx / api.1pay.cx only.
 *
 * @copyright 1pay.cx
 * @license   MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/onepaycx/lib/bootstrap.php';

/**
 * Module metadata.
 *
 * @return array
 */
function onepaycx_MetaData()
{
    return array(
        'DisplayName'                => '1pay.cx',
        'APIVersion'                 => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'           => false,
    );
}

/**
 * Configuration fields shown under Setup > Payments > Payment Gateways.
 *
 * @return array
 */
function onepaycx_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => '1pay.cx',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API key',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'From your 1pay.cx dashboard: Settings -> API keys. Looks like pk_xxx.yyy. '
                . 'The webhook is configured automatically; nothing else to set up.',
        ),
        'method' => array(
            'FriendlyName' => 'Payment method',
            'Type'         => 'dropdown',
            'Options'      => array(
                'any'  => 'Buyer chooses (card, Bitcoin or Monero)',
                'card' => 'Card only',
                'btc'  => 'Bitcoin only',
                'xmr'  => 'Monero only',
            ),
            'Default'      => 'any',
            'Description'  => 'Send buyers straight to one method, or let them pick on the 1pay.cx checkout.',
        ),
        'buttonText' => array(
            'FriendlyName' => 'Button text',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'Pay with 1pay.cx',
            'Description'  => 'Label of the payment button on the invoice.',
        ),
    );
}

/**
 * Validate the configuration when the admin saves it (WHMCS 8+): verify the API
 * key against GET /v1/me and cache the webhook signing secret. Only a rejected
 * key blocks the save; a network hiccup does not, because the secret is also
 * fetched on demand when the first notify arrives.
 *
 * @param array $params
 * @throws Exception
 */
function onepaycx_config_validate($params)
{
    $apiKey = isset($params['apiKey']) ? trim((string) $params['apiKey']) : '';
    if ($apiKey === '') {
        return;
    }
    $client = new OnePayCx_Whmcs_Client($apiKey);
    $res = $client->me();
    if ($res['ok'] && is_array($res['data']) && !empty($res['data']['webhookSecret']) && is_string($res['data']['webhookSecret'])) {
        OnePayCx_Whmcs_Store::cacheWebhookSecret($apiKey, $res['data']['webhookSecret']);
        return;
    }
    if ($res['status'] === 401 || $res['status'] === 403) {
        $message = '1pay.cx rejected this API key. Copy it again from your 1pay.cx dashboard (Settings -> API keys).';
        if (class_exists('\\WHMCS\\Exception\\Module\\InvalidConfiguration')) {
            throw new \WHMCS\Exception\Module\InvalidConfiguration($message);
        }
        throw new \Exception($message);
    }
    // Any other failure (network, 5xx): allow the save; the secret is fetched later.
}

/**
 * Build the invoice-page payment button. Creates the payment on 1pay.cx (or
 * reuses a still-open one for the same invoice/amount/currency) and renders a
 * button-styled link that sends the buyer to the hosted checkout.
 *
 * @param array $params gateway + invoice parameters supplied by WHMCS
 * @return string HTML
 */
function onepaycx_link($params)
{
    $apiKey = isset($params['apiKey']) ? trim((string) $params['apiKey']) : '';
    if ($apiKey === '') {
        return onepaycx_notice('1pay.cx is not configured yet: add your API key under Setup -> Payments -> Payment Gateways.');
    }

    $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;
    if ($invoiceId <= 0) {
        return onepaycx_notice('1pay.cx: missing invoice.');
    }

    $amount = number_format((float) (isset($params['amount']) ? $params['amount'] : 0), 2, '.', '');
    $currency = strtoupper(trim((string) (isset($params['currency']) ? $params['currency'] : 'USD')));
    if ((float) $amount <= 0) {
        return onepaycx_notice('1pay.cx: nothing to pay on this invoice.');
    }
    if (strlen($currency) !== 3) {
        return onepaycx_notice('1pay.cx: unsupported invoice currency.');
    }

    $buttonText = isset($params['buttonText']) && trim((string) $params['buttonText']) !== ''
        ? trim((string) $params['buttonText'])
        : 'Pay with 1pay.cx';

    $checkout = '';
    $reuse = OnePayCx_Whmcs_Store::findReusable($invoiceId, $amount, $currency);
    if ($reuse !== null) {
        $checkout = $reuse['checkout_url'];
    }

    if ($checkout === '') {
        $systemUrl = isset($params['systemurl']) ? rtrim(trim((string) $params['systemurl']), '/') . '/' : '';
        if ($systemUrl === '/' || strpos($systemUrl, 'http') !== 0) {
            return onepaycx_notice('1pay.cx: the WHMCS System URL is not set (Setup -> General Settings).');
        }
        $body = array(
            'amount'      => (float) $amount,
            'currency'    => $currency,
            'reference'   => (string) $invoiceId,
            'productName' => onepaycx_product_name($params, $invoiceId),
            'notifyUrl'   => $systemUrl . 'modules/gateways/callback/onepaycx.php',
            'redirectUrl' => $systemUrl . 'modules/gateways/callback/onepaycx.php?action=return&invoiceid=' . $invoiceId,
        );
        $email = isset($params['clientdetails']['email']) ? trim((string) $params['clientdetails']['email']) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $body['email'] = $email;
        }
        $method = isset($params['method']) ? trim((string) $params['method']) : '';
        if ($method !== '' && $method !== 'any' && preg_match('/^[a-z0-9-]{1,40}$/', $method)) {
            $body['method'] = $method;
        }

        $client = new OnePayCx_Whmcs_Client($apiKey);
        $res = $client->createPayment($body);
        $paymentId = ($res['ok'] && is_array($res['data']) && isset($res['data']['id'])) ? (string) $res['data']['id'] : '';
        $checkout = ($res['ok'] && is_array($res['data']) && isset($res['data']['checkoutUrl']))
            ? OnePayCx_Whmcs_Client::checkoutUrl($res['data']['checkoutUrl'])
            : '';

        if ($checkout === '' || !OnePayCx_Whmcs_Client::isPaymentId($paymentId)) {
            $why = $res['error'] !== '' ? $res['error'] : 'payment could not be started';
            return onepaycx_notice('1pay.cx: could not start the payment (' . $why . '). Please try again in a moment.');
        }
        OnePayCx_Whmcs_Store::recordPayment($invoiceId, $paymentId, $amount, $currency, $checkout);
    }

    // A link, not a GET form: submitting a GET form replaces the action URL's
    // query string with the (empty) form data, which would drop the `?method=`
    // that 1pay.cx appends to checkoutUrl when a payment method is preset.
    return '<a class="btn btn-primary onepaycx-pay" href="' . onepaycx_esc($checkout) . '">'
        . onepaycx_esc($buttonText)
        . '</a>';
}

/** "Invoice #123 — Acme Hosting" style product name, capped to a sane length. */
function onepaycx_product_name(array $params, $invoiceId)
{
    $name = isset($params['description']) ? trim((string) $params['description']) : '';
    if ($name === '') {
        $name = 'Invoice #' . $invoiceId;
    }
    if (isset($params['companyname']) && trim((string) $params['companyname']) !== '') {
        $name .= ' - ' . trim((string) $params['companyname']);
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 120);
    }
    return substr($name, 0, 120);
}

/** HTML-escape for attribute/text context. */
function onepaycx_esc($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A Bootstrap-styled inline message instead of a broken button. */
function onepaycx_notice($message)
{
    return '<div class="alert alert-warning onepaycx-notice">' . onepaycx_esc($message) . '</div>';
}
