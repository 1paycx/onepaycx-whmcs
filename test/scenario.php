<?php
/**
 * One harness scenario, run in its own PHP process by test/run.php (the callback
 * file exit()s, so each request needs a fresh process).
 *
 *   php test/scenario.php <name>
 *
 * Environment (set by run.php): ONEPAYCX_TEST_ROOT (fake WHMCS root),
 * ONEPAYCX_TEST_LOG, ONEPAYCX_TEST_DB, ONEPAYCX_TEST_TRANSPORT, ONEPAYCX_TEST_APIKEY,
 * ONEPAYCX_TEST_LINKMETHOD (the module's "Payment method" setting for link()),
 * and for callback scenarios ONEPAYCX_TEST_METHOD / _QUERY / _SIG / _SIG2 / _BODY.
 *
 * @license MIT
 */

$root = rtrim((string) getenv('ONEPAYCX_TEST_ROOT'), '/');
$name = isset($argv[1]) ? $argv[1] : '';

if (strpos($name, 'module') === 0) {
    require_once __DIR__ . '/stubs/whmcs.php';
    require_once $root . '/modules/gateways/onepaycx.php';
    onepaycx_test_install_transport();

    $out = array();
    $out['meta'] = onepaycx_MetaData();
    $out['config'] = onepaycx_config();

    $cfg = array('apiKey' => getenv('ONEPAYCX_TEST_APIKEY'));
    try {
        onepaycx_config_validate($cfg);
        $out['validate'] = 'ok';
    } catch (\Throwable $e) {
        $out['validate'] = get_class($e) . ': ' . $e->getMessage();
    }

    $params = array(
        'apiKey'        => getenv('ONEPAYCX_TEST_APIKEY'),
        'method'        => (string) getenv('ONEPAYCX_TEST_LINKMETHOD'),
        'buttonText'    => 'Pay with 1pay.cx',
        'invoiceid'     => 42,
        'description'   => 'Invoice #42',
        'amount'        => '10.00',
        'currency'      => 'usd',
        'companyname'   => 'Example Hosting',
        'systemurl'     => 'https://billing.example.test/',
        'returnurl'     => 'https://billing.example.test/viewinvoice.php?id=42',
        'langpaynow'    => 'Pay Now',
        'clientdetails' => array('email' => 'buyer@example.test', 'firstname' => 'Ada'),
    );
    $out['link1'] = onepaycx_link($params);
    $out['link2'] = onepaycx_link($params);
    echo json_encode($out);
    exit(0);
}

// Callback scenarios: the fake init.php (written by run.php) loads the stubs and
// primes the request from the environment; then the callback runs to completion.
$query = array();
parse_str((string) getenv('ONEPAYCX_TEST_QUERY'), $query);
$_GET = $query;
$_SERVER['REQUEST_METHOD'] = getenv('ONEPAYCX_TEST_METHOD') ?: 'POST';
if (getenv('ONEPAYCX_TEST_SIG') !== false && getenv('ONEPAYCX_TEST_SIG') !== '') {
    $_SERVER['HTTP_X_1PAY_SIGNATURE'] = getenv('ONEPAYCX_TEST_SIG');
}
if (getenv('ONEPAYCX_TEST_SIG2') !== false && getenv('ONEPAYCX_TEST_SIG2') !== '') {
    $_SERVER['HTTP_X_1PAY_SIGNATURE_V2'] = getenv('ONEPAYCX_TEST_SIG2');
}
define('ONEPAYCX_INPUT_STREAM', (string) getenv('ONEPAYCX_TEST_BODY'));

require $root . '/modules/gateways/callback/onepaycx.php';
