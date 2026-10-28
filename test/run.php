<?php
/**
 * Compile + behaviour harness for the 1pay.cx WHMCS gateway.
 *
 * Builds a throw-away fake WHMCS root (init.php + includes/*.php are stubs that
 * load test/stubs/whmcs.php), copies the module files into it exactly as they
 * would be installed, then runs every entry point at least once in isolated
 * PHP processes with a canned HTTP transport — nothing touches the network.
 *
 *   php plugins/whmcs/test/run.php
 *
 * Exit code 0 = every scenario passed.
 *
 * @license MIT
 */

$pluginDir = dirname(__DIR__);
$root = rtrim(sys_get_temp_dir(), '/') . '/onepaycx-whmcs-harness-' . getmypid();
$log = $root . '/calls.log';
$db = $root . '/db.json';
$bodyFile = $root . '/body.bin';
$apiKey = 'pk_test_harness-key-never-logged';
$secret1 = 'whsec_' . str_repeat('a', 48);
$secret2 = 'whsec_' . str_repeat('b', 48);

$failures = 0;
$checks = 0;

function rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}

function copyTree($src, $dst)
{
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir($src . '/' . $f) ? copyTree($src . '/' . $f, $dst . '/' . $f) : copy($src . '/' . $f, $dst . '/' . $f);
    }
}

function check($cond, $label)
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label\n";
    }
}

function readLog($file)
{
    $out = array();
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $out[] = json_decode($line, true);
        }
    }
    return $out;
}

function logOf(array $entries, $kind)
{
    return array_values(array_filter($entries, function ($e) use ($kind) {
        return isset($e['kind']) && $e['kind'] === $kind;
    }));
}

function httpCalls(array $entries, $method, $path)
{
    return array_values(array_filter(logOf($entries, 'http'), function ($e) use ($method, $path) {
        return $e['method'] === $method && $e['path'] === $path;
    }));
}

/** Run one scenario in a subprocess; returns [exitCode, stdout, logEntries]. */
function runScenario($name, array $env, $body = '')
{
    global $root, $log, $db, $bodyFile, $apiKey, $pluginDir;
    @unlink($log);
    file_put_contents($bodyFile, $body);
    $env = array_merge(array(
        'ONEPAYCX_TEST_ROOT'      => $root,
        'ONEPAYCX_TEST_LOG'       => $log,
        'ONEPAYCX_TEST_DB'        => $db,
        'ONEPAYCX_TEST_APIKEY'    => $apiKey,
        'ONEPAYCX_TEST_BODY'      => $bodyFile,
        'ONEPAYCX_TEST_TRANSPORT' => '[]',
        'ONEPAYCX_TEST_QUERY'     => '',
        'ONEPAYCX_TEST_METHOD'    => 'POST',
        'ONEPAYCX_TEST_SIG'       => '',
        'ONEPAYCX_TEST_SIG2'      => '',
        'ONEPAYCX_TEST_CONVERTTO' => '',
        'ONEPAYCX_TEST_INACTIVE'  => '',
        'PATH'                    => getenv('PATH'),
    ), $env);
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg($pluginDir . '/test/scenario.php') . ' ' . escapeshellarg($name);
    $proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, $env);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    echo "\n[$name] exit=$code\n";
    if ($stderr !== '') {
        echo "  stderr: " . trim($stderr) . "\n";
    }
    if (preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $stdout . $stderr)) {
        echo "  PHP diagnostics in output:\n" . $stdout . $stderr . "\n";
    }
    return array($code, $stdout, readLog($log), $stderr);
}

function dbLoad()
{
    global $db;
    return json_decode((string) file_get_contents($db), true);
}

function dbSave(array $data)
{
    global $db;
    file_put_contents($db, json_encode($data, JSON_PRETTY_PRINT));
}

/* ------------------------------------------------------------ fake root */

rrmdir($root);
mkdir($root . '/includes', 0777, true);
copyTree($pluginDir . '/modules', $root . '/modules');

$stubs = $pluginDir . '/test/stubs/whmcs.php';
file_put_contents($root . '/init.php', "<?php\n"
    . "require_once " . var_export($stubs, true) . ";\n"
    . "require_once __DIR__ . '/modules/gateways/onepaycx/lib/bootstrap.php';\n"
    . "onepaycx_test_install_transport();\n");
file_put_contents($root . '/includes/gatewayfunctions.php', "<?php\n// stub: functions live in test/stubs/whmcs.php\n");
file_put_contents($root . '/includes/invoicefunctions.php', "<?php\n// stub: functions live in test/stubs/whmcs.php\n");

// Seed the fake database: invoices 42-49 for client 7 (currency 1 = USD; 2 = EUR). No module table yet.
dbSave(array(
    'tblpaymentgateways' => array(array('id' => 1, 'gateway' => 'onepaycx', 'setting' => 'apiKey', 'value' => 'ignored-by-stub', 'order' => 0)),
    'tblaccounts'        => array(),
    'tblinvoices'        => array(
        array('id' => 42, 'userid' => 7), array('id' => 43, 'userid' => 7), array('id' => 44, 'userid' => 7),
        array('id' => 45, 'userid' => 7), array('id' => 46, 'userid' => 7), array('id' => 47, 'userid' => 7),
        array('id' => 48, 'userid' => 7), array('id' => 49, 'userid' => 7),
    ),
    'tblclients'         => array(array('id' => 7, 'currency' => 1)),
    'tblcurrencies'      => array(array('id' => 1, 'code' => 'USD'), array('id' => 2, 'code' => 'EUR')),
));

echo "Fake WHMCS root: $root\n";

/* ------------------------------------------------------------ 1. lint */

echo "\n[lint]\n";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (substr($f->getFilename(), -4) !== '.php') {
        continue;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $o, $c);
    check($c === 0, 'php -l ' . substr($f->getPathname(), strlen($pluginDir) + 1));
}

/* ------------------------------------------------------------ 2. module */

$me = array('method' => 'GET', 'path' => '/v1/me', 'status' => 200, 'body' => array('id' => 'm_1', 'email' => 'shop@example.test', 'webhookSecret' => $secret1));
$create = array('method' => 'POST', 'path' => '/v1/payments', 'status' => 200, 'body' => array('id' => 'pay_abc', 'checkoutUrl' => '/pay/pay_abc'));
list($code, $out, $entries) = runScenario('module', array('ONEPAYCX_TEST_TRANSPORT' => json_encode(array($me, $create))));
$r = json_decode($out, true);
check($code === 0 && is_array($r), 'module scenario ran and returned JSON');
check(isset($r['meta']['DisplayName']) && $r['meta']['DisplayName'] === '1pay.cx', 'MetaData DisplayName');
check(isset($r['meta']['DisableLocalCreditCardInput']) && $r['meta']['DisableLocalCreditCardInput'] === true, 'MetaData disables local card input');
check(isset($r['config']['apiKey']['Type']) && $r['config']['apiKey']['Type'] === 'password', 'config has a password-type apiKey field');
check(isset($r['config']['FriendlyName']['Value']) && $r['config']['FriendlyName']['Value'] === '1pay.cx', 'config FriendlyName');
check(isset($r['validate']) && $r['validate'] === 'ok', 'config_validate accepts a working key');
check(count(httpCalls($entries, 'GET', '/v1/me')) === 1, 'config_validate fetched /v1/me exactly once');
$creates = httpCalls($entries, 'POST', '/v1/payments');
check(count($creates) === 1, 'two link() renders created exactly one payment (second reused the open one)');
$b = $creates ? $creates[0]['body'] : array();
check(isset($b['amount']) && !is_string($b['amount']) && is_numeric($b['amount']) && (float) $b['amount'] === 10.0, 'create body: amount is a JSON number 10');
check(isset($b['currency']) && $b['currency'] === 'USD', 'create body: currency upper-cased ISO code');
check(isset($b['reference']) && $b['reference'] === '42', 'create body: reference = invoice id');
check(isset($b['email']) && $b['email'] === 'buyer@example.test', 'create body: client email forwarded');
check(isset($b['notifyUrl']) && $b['notifyUrl'] === 'https://billing.example.test/modules/gateways/callback/onepaycx.php', 'create body: notifyUrl points at the callback');
check(isset($b['redirectUrl']) && $b['redirectUrl'] === 'https://billing.example.test/modules/gateways/callback/onepaycx.php?action=return&invoiceid=42', 'create body: redirectUrl returns via the callback');
check(isset($b['productName']) && strpos($b['productName'], 'Invoice #42') === 0, 'create body: productName from invoice description');
check(!array_key_exists('method', $b), 'create body: no method when "buyer chooses"');
check(strpos($r['link1'], 'href="https://1pay.cx/pay/pay_abc"') !== false, 'link() renders a link to https://1pay.cx{checkoutUrl}');
check(strpos($r['link1'], '<form') === false, 'link() is an anchor, not a GET form (a GET form would drop the checkout query string)');
check(strpos($r['link1'], '>Pay with 1pay.cx</a>') !== false, 'link() renders the Pay with 1pay.cx button');
check($r['link1'] === $r['link2'], 'second link() render is identical (reused checkout)');
foreach (logOf($entries, 'http') as $h) {
    check($h['host'] === 'api.1pay.cx' && $h['bearer'] === true && strpos($h['ua'], 'onepaycx-whmcs/1.1.0') === 0, 'HTTP call only to api.1pay.cx with bearer + UA (' . $h['method'] . ' ' . $h['path'] . ')');
}
$state = dbLoad();
$secretRow = null;
foreach ($state['tblpaymentgateways'] as $row) {
    if ($row['setting'] === 'webhookSecret') {
        $secretRow = $row;
    }
}
check($secretRow !== null && strpos($secretRow['value'], 'enc:') === 0 && strpos($secretRow['value'], $secret1) === false, 'webhook secret cached in tblpaymentgateways, encrypted');
check(isset($state['mod_onepaycx_payments'][0]['payment_id']) && $state['mod_onepaycx_payments'][0]['payment_id'] === 'pay_abc', 'payment recorded in mod_onepaycx_payments');
$leak = strpos(file_get_contents($db), $apiKey) !== false || strpos(json_encode($entries), $apiKey) !== false || strpos(json_encode($entries), $secret1) !== false;
check(!$leak, 'neither the API key nor the secret appears in the DB dump or the call log');

// Rejected key -> config_validate throws.
list($code, $out, $entries) = runScenario('module', array('ONEPAYCX_TEST_TRANSPORT' => json_encode(array(
    array('method' => 'GET', 'path' => '/v1/me', 'status' => 401, 'body' => array('error' => 'unauthorized')),
    $create,
))));
$r = json_decode($out, true);
check(isset($r['validate']) && strpos($r['validate'], 'InvalidConfiguration') !== false, 'config_validate throws InvalidConfiguration on a 401');
check(strpos($r['link1'], 'https://1pay.cx/pay/pay_abc') !== false, 'link() still works after a failed validate (reused open payment)');

// API failure -> friendly notice, no button.
$state = dbLoad();
$state['mod_onepaycx_payments'] = array();
dbSave($state);
list($code, $out, $entries) = runScenario('module', array('ONEPAYCX_TEST_TRANSPORT' => json_encode(array(
    $me,
    array('method' => 'POST', 'path' => '/v1/payments', 'status' => 400, 'body' => array('error' => 'invalid')),
))));
$r = json_decode($out, true);
check(strpos($r['link1'], 'alert-warning') !== false && strpos($r['link1'], 'invalid') !== false, 'link() shows a warning when the API rejects the payment');
check(strpos($r['link1'], '1pay.cx/pay/') === false, 'no button rendered on API failure');

// "Bitcoin only": the method is sent and the ?method= on checkoutUrl survives into the button.
list($code, $out, $entries) = runScenario('module', array(
    'ONEPAYCX_TEST_LINKMETHOD' => 'btc',
    'ONEPAYCX_TEST_TRANSPORT'  => json_encode(array(
        $me,
        array('method' => 'POST', 'path' => '/v1/payments', 'status' => 200, 'body' => array('id' => 'pay_btc', 'checkoutUrl' => '/pay/pay_btc?method=btc')),
    )),
));
$r = json_decode($out, true);
$creates = httpCalls($entries, 'POST', '/v1/payments');
check(count($creates) === 1 && isset($creates[0]['body']['method']) && $creates[0]['body']['method'] === 'btc', 'create body: method=btc when "Bitcoin only" is set');
check(strpos($r['link1'], 'href="https://1pay.cx/pay/pay_btc?method=btc"') !== false, 'link() keeps ?method=btc in the checkout link');
$state = dbLoad();
$state['mod_onepaycx_payments'] = array();
dbSave($state);

// Restore a recorded open payment for invoice 42 for the notify scenarios.
list($code, $out, $entries) = runScenario('module', array('ONEPAYCX_TEST_TRANSPORT' => json_encode(array($me, $create))));

/* ------------------------------------------------------------ 3. notify */

$paidRow = function ($id, $ref, $amount, $status = 'paid', $currency = 'USD', $extra = array()) {
    return array('method' => 'GET', 'path' => '/v1/payments/' . $id, 'status' => 200, 'body' => array('id' => $id, 'reference' => $ref, 'amount' => $amount, 'currency' => $currency, 'status' => $status) + $extra);
};
$seedRow = function ($id, $invoiceId, $amount, $currency = 'USD') {
    $state = dbLoad();
    $state['mod_onepaycx_payments'][] = array('id' => 200 + count($state['mod_onepaycx_payments']), 'invoice_id' => $invoiceId, 'payment_id' => $id, 'amount' => $amount, 'currency' => $currency, 'status' => 'pending', 'checkout_url' => 'https://1pay.cx/pay/' . $id, 'created_at' => date('Y-m-d H:i:s'));
    dbSave($state);
};
$notifyBody = function ($id, $ref, $amount, array $extra = array()) {
    return json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => $id, 'reference' => $ref, 'amount' => $amount, 'status' => 'paid') + $extra, 'at' => '2026-09-08T10:00:00.000Z'));
};
$v2 = function ($body, $secret, $t = null) {
    $t = $t === null ? time() : $t;
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
};

// data.amount is the API's charged figure, which includes the merchant's customer
// surcharge (here 3%): the invoice must be credited the 10.00 the module recorded.
$paidBody = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'pay_abc', 'reference' => '42', 'amount' => 10.3, 'valueForwarded' => 9.99, 'status' => 'paid', 'livemode' => false), 'at' => '2026-09-08T10:00:00.000Z'));
$sig = hash_hmac('sha256', $paidBody, $secret1);

list($code, $out, $entries) = runScenario('notify-ok', array('ONEPAYCX_TEST_SIG' => $sig, 'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_abc', '42', 10.3)))), $paidBody);
check($code === 0 && trim($out) === 'ok', 'valid notify -> "ok"');
check(count(httpCalls($entries, 'GET', '/v1/me')) === 0, 'cached secret used: no /v1/me call');
check(count(httpCalls($entries, 'GET', '/v1/payments/pay_abc')) === 1, 'notify re-reads GET /v1/payments/pay_abc before crediting');
check(count(logOf($entries, 'checkCbInvoiceID')) === 1 && logOf($entries, 'checkCbInvoiceID')[0]['invoiceId'] === 42, 'checkCbInvoiceID(42)');
check(count(logOf($entries, 'checkCbTransID')) === 1 && logOf($entries, 'checkCbTransID')[0]['transId'] === 'pay_abc', 'checkCbTransID(pay_abc)');
$lt = logOf($entries, 'logTransaction');
check(count($lt) === 1 && $lt[0]['result'] === 'Successful' && $lt[0]['data']['event'] === 'payment.paid', 'logTransaction(Successful) with the payload');
$aip = logOf($entries, 'addInvoicePayment');
check(count($aip) === 1 && $aip[0]['invoiceId'] === 42 && $aip[0]['transId'] === 'pay_abc' && $aip[0]['amount'] === '10.00' && $aip[0]['fees'] === '0.00' && $aip[0]['gateway'] === 'onepaycx', 'addInvoicePayment(42, pay_abc, "10.00", "0.00", onepaycx): recorded amount, not the surcharged API amount');
$statuses = array_map(function ($e) { return $e['values']['status']; }, array_values(array_filter(logOf($entries, 'db.update'), function ($e) { return $e['table'] === 'mod_onepaycx_payments'; })));
check($statuses === array('crediting', 'paid'), 'local row claimed (crediting) before addInvoicePayment, then marked paid');
$state = dbLoad();
check($state['mod_onepaycx_payments'][0]['status'] === 'paid', 'local payment row marked paid');

// Same notify again (at-least-once) -> no double credit.
list($code, $out, $entries) = runScenario('notify-dup', array('ONEPAYCX_TEST_SIG' => $sig, 'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_abc', '42', 10.3)))), $paidBody);
check($code === 0 && trim($out) === 'already processed', 'duplicate notify -> "already processed"');
check(count(logOf($entries, 'addInvoicePayment')) === 0, 'duplicate notify does not credit again');

// Signature over a tampered body -> 401, no credit, no refetch (throttled).
$tampered = str_replace('"amount":10.3', '"amount":1000', $paidBody);
list($code, $out, $entries) = runScenario('notify-badsig', array('ONEPAYCX_TEST_SIG' => $sig), $tampered);
check(trim($out) === 'bad signature', 'tampered body -> "bad signature"');
check(count(logOf($entries, 'addInvoicePayment')) === 0, 'bad signature never credits');
check(count(httpCalls($entries, 'GET', '/v1/me')) === 0, 'secret refetch throttled (fetched < 60s ago)');
check(count(logOf($entries, 'logTransaction')) === 1 && logOf($entries, 'logTransaction')[0]['result'] === 'Unsuccessful', 'bad signature logged as Unsuccessful');

// Missing signature -> 400.
list($code, $out, $entries) = runScenario('notify-nosig', array(), $paidBody);
check(trim($out) === 'missing signature', 'missing header -> "missing signature"');

// Rotated secret: cache is stale (fetched long ago), /v1/me returns the new one.
$state = dbLoad();
foreach ($state['tblpaymentgateways'] as $i => $row) {
    if ($row['setting'] === 'webhookSecretAt') {
        $state['tblpaymentgateways'][$i]['value'] = (string) (time() - 3600);
    }
}
dbSave($state);
$seedRow('pay_def', 43, '5.50');
$paidBody2 = $notifyBody('pay_def', '43', 5.5);
$sig2 = hash_hmac('sha256', $paidBody2, $secret2);
list($code, $out, $entries) = runScenario('notify-rotated', array(
    'ONEPAYCX_TEST_SIG' => $sig2,
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array(array('method' => 'GET', 'path' => '/v1/me', 'status' => 200, 'body' => array('id' => 'm_1', 'webhookSecret' => $secret2)), $paidRow('pay_def', '43', 5.5))),
), $paidBody2);
check(trim($out) === 'ok', 'rotated secret: refetched from /v1/me and verified');
check(count(httpCalls($entries, 'GET', '/v1/me')) === 1, 'rotated secret: exactly one /v1/me call');
$aip = logOf($entries, 'addInvoicePayment');
check(count($aip) === 1 && $aip[0]['invoiceId'] === 43 && $aip[0]['amount'] === '5.50', 'rotated secret: invoice 43 credited 5.50');

// Uppercase hex signature is accepted too.
$seedRow('pay_ghi', 43, '1.00');
$paidBody3 = $notifyBody('pay_ghi', '43', 1);
list($code, $out, $entries) = runScenario('notify-upper', array('ONEPAYCX_TEST_SIG' => strtoupper(hash_hmac('sha256', $paidBody3, $secret2)), 'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_ghi', '43', 1)))), $paidBody3);
check(trim($out) === 'ok', 'upper-case hex signature accepted');

// Other events are acknowledged and ignored.
$other = json_encode(array('event' => 'payment.expired', 'data' => array('paymentId' => 'pay_x'), 'at' => '2026-09-08T10:07:00.000Z'));
list($code, $out, $entries) = runScenario('notify-ignored', array('ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $other, $secret2)), $other);
check(trim($out) === 'ignored' && count(logOf($entries, 'addInvoicePayment')) === 0, 'non-payment event -> "ignored"');

// A payment this module never recorded is refused outright, without an API call.
$unknown = $notifyBody('pay_jkl', '46', 3);
list($code, $out, $entries) = runScenario('notify-unknown', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $unknown, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_jkl', '46', 3))),
), $unknown);
check(trim($out) === 'ignored: unknown payment' && count(logOf($entries, 'addInvoicePayment')) === 0, 'no local record -> "ignored: unknown payment", no credit');
check(count(logOf($entries, 'http')) === 0, 'no local record -> no API call');

// V2 header: valid and fresh -> credited (the legacy header is not needed).
$seedRow('pay_v2a', 43, '2.00');
$bodyV2 = $notifyBody('pay_v2a', '43', 2);
list($code, $out, $entries) = runScenario('notify-v2', array('ONEPAYCX_TEST_SIG2' => $v2($bodyV2, $secret2), 'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_v2a', '43', 2)))), $bodyV2);
check(trim($out) === 'ok' && count(logOf($entries, 'addInvoicePayment')) === 1, 'V2 signature (fresh) -> credited');

// V2 wins over legacy: a valid legacy header does not rescue a stale V2 (replay).
$seedRow('pay_v2b', 43, '2.00');
$bodyV2b = $notifyBody('pay_v2b', '43', 2);
list($code, $out, $entries) = runScenario('notify-v2-stale', array(
    'ONEPAYCX_TEST_SIG2' => $v2($bodyV2b, $secret2, time() - 301),
    'ONEPAYCX_TEST_SIG'  => hash_hmac('sha256', $bodyV2b, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_v2b', '43', 2))),
), $bodyV2b);
check(trim($out) === 'bad signature' && count(logOf($entries, 'addInvoicePayment')) === 0, 'V2 timestamp 301 s old -> "bad signature" even with a valid legacy header');
list($code, $out, $entries) = runScenario('notify-v2-future', array('ONEPAYCX_TEST_SIG2' => $v2($bodyV2b, $secret2, time() + 400)), $bodyV2b);
check(trim($out) === 'bad signature', 'V2 timestamp 400 s in the future -> "bad signature"');
list($code, $out, $entries) = runScenario('notify-v2-wrong', array('ONEPAYCX_TEST_SIG2' => $v2($bodyV2b, $secret1)), $bodyV2b);
check(trim($out) === 'bad signature', 'V2 under the wrong secret -> "bad signature"');
list($code, $out, $entries) = runScenario('notify-v2-malformed', array('ONEPAYCX_TEST_SIG2' => 'v1=abc', 'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyV2b, $secret2)), $bodyV2b);
check(trim($out) === 'missing signature', 'malformed V2 header -> "missing signature" (no fallback to legacy)');

// Currency mismatch between the record (USD) and the API row (EUR) -> refused.
$seedRow('pay_mis', 43, '3.00');
$bodyMis = $notifyBody('pay_mis', '43', 3);
list($code, $out, $entries) = runScenario('notify-currency-mismatch', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyMis, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_mis', '43', 3, 'paid', 'EUR'))),
), $bodyMis);
check(trim($out) === 'ignored: currency mismatch' && count(logOf($entries, 'addInvoicePayment')) === 0, 'currency mismatch (EUR payment, USD record) -> "ignored: currency mismatch", no credit');
check(count(logOf($entries, 'logTransaction')) === 1 && logOf($entries, 'logTransaction')[0]['result'] === 'Unsuccessful', 'currency mismatch logged as Unsuccessful');

// Short payment -> refused; 0.01 rounding tolerated.
$seedRow('pay_sht', 43, '10.00');
$bodySht = $notifyBody('pay_sht', '43', 9.5);
list($code, $out, $entries) = runScenario('notify-short', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodySht, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_sht', '43', 9.5))),
), $bodySht);
check(trim($out) === 'ignored: amount mismatch' && count(logOf($entries, 'addInvoicePayment')) === 0, 'API amount 9.50 < recorded 10.00 -> "ignored: amount mismatch"');
list($code, $out, $entries) = runScenario('notify-rounding', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodySht, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_sht', '43', 9.995))),
), $bodySht);
$aip = logOf($entries, 'addInvoicePayment');
check(trim($out) === 'ok' && count($aip) === 1 && $aip[0]['amount'] === '10.00', 'API amount 9.995 vs recorded 10.00 (rounding) -> credited 10.00');

// Signed notify but the API says it is not paid (or the read fails) -> non-2xx, retried, no credit.
$seedRow('pay_npd', 43, '2.00');
$bodyNpd = $notifyBody('pay_npd', '43', 2);
list($code, $out, $entries) = runScenario('notify-not-paid', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyNpd, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_npd', '43', 2, 'pending'))),
), $bodyNpd);
check(trim($out) === 'payment not paid' && count(logOf($entries, 'addInvoicePayment')) === 0, 'API status pending -> "payment not paid", no credit');
list($code, $out, $entries) = runScenario('notify-api-down', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyNpd, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array(array('method' => 'GET', 'path' => '/v1/payments/pay_npd', 'status' => 503, 'body' => array('error' => 'down')))),
), $bodyNpd);
check(trim($out) === 'could not verify payment' && count(logOf($entries, 'addInvoicePayment')) === 0, 'API read fails -> "could not verify payment", no credit');

// API row says another invoice -> refused.
list($code, $out, $entries) = runScenario('notify-ref-mismatch', array(
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyNpd, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_npd', '999', 2))),
), $bodyNpd);
check(trim($out) === 'ignored: invoice mismatch' && count(logOf($entries, 'addInvoicePayment')) === 0, 'API reference differs from the recorded invoice -> "ignored: invoice mismatch"');

// Live key: a test payment is refused (from the body, and from the API row).
$liveKey = 'pk_live_harness-key';
$meLive = array('method' => 'GET', 'path' => '/v1/me', 'status' => 200, 'body' => array('id' => 'm_1', 'webhookSecret' => $secret2, 'livemode' => true));
$bodyLiveTest = $notifyBody('pay_npd', '43', 2, array('livemode' => false));
list($code, $out, $entries) = runScenario('notify-live-testbody', array(
    'ONEPAYCX_TEST_APIKEY' => $liveKey,
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyLiveTest, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($meLive, $paidRow('pay_npd', '43', 2))),
), $bodyLiveTest);
check(trim($out) === 'ignored: test payment on a live key' && count(logOf($entries, 'addInvoicePayment')) === 0, 'live key + livemode:false notify -> refused');
check(count(httpCalls($entries, 'GET', '/v1/payments/pay_npd')) === 0, 'live key + livemode:false notify -> no payment re-read');
list($code, $out, $entries) = runScenario('notify-live-testrow', array(
    'ONEPAYCX_TEST_APIKEY' => $liveKey,
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyNpd, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($meLive, $paidRow('pay_npd', '43', 2, 'paid', 'USD', array('livemode' => false)))),
), $bodyNpd);
check(trim($out) === 'ignored: test payment on a live key' && count(logOf($entries, 'addInvoicePayment')) === 0, 'live key + API row livemode:false -> refused');
list($code, $out, $entries) = runScenario('notify-live-ok', array(
    'ONEPAYCX_TEST_APIKEY' => $liveKey,
    'ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $bodyNpd, $secret2),
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($meLive, $paidRow('pay_npd', '43', 2, 'paid', 'USD', array('livemode' => true)))),
), $bodyNpd);
check(trim($out) === 'ok' && count(logOf($entries, 'addInvoicePayment')) === 1, 'live key + livemode:true -> credited');

// Concurrency lock: a row another request is crediting right now is a duplicate; a stale
// lock (request died mid-credit) is taken over and credited with the recorded amount.
$state = dbLoad();
$state['mod_onepaycx_payments'][] = array('id' => 90, 'invoice_id' => 48, 'payment_id' => 'pay_lck', 'amount' => '4.00', 'currency' => 'USD', 'status' => 'crediting', 'checkout_url' => 'https://1pay.cx/pay/pay_lck', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'));
$state['mod_onepaycx_payments'][] = array('id' => 91, 'invoice_id' => 49, 'payment_id' => 'pay_stl', 'amount' => '4.00', 'currency' => 'USD', 'status' => 'crediting', 'checkout_url' => 'https://1pay.cx/pay/pay_stl', 'created_at' => date('Y-m-d H:i:s', time() - 900), 'updated_at' => date('Y-m-d H:i:s', time() - 600));
dbSave($state);
$lockBody = $notifyBody('pay_lck', '48', 4.12);
list($code, $out, $entries) = runScenario('notify-locked', array('ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $lockBody, $secret2), 'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($meLive, $paidRow('pay_lck', '48', 4.12)))), $lockBody);
check(trim($out) === 'already processed' && count(logOf($entries, 'addInvoicePayment')) === 0, 'row being credited by another request -> "already processed", no second credit');
$staleBody = $notifyBody('pay_stl', '49', 4.12);
list($code, $out, $entries) = runScenario('notify-stale-lock', array('ONEPAYCX_TEST_SIG' => hash_hmac('sha256', $staleBody, $secret2), 'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_stl', '49', 4.12)))), $staleBody);
$aip = logOf($entries, 'addInvoicePayment');
check(trim($out) === 'ok' && count($aip) === 1 && $aip[0]['invoiceId'] === 49 && $aip[0]['amount'] === '4.00', 'stale crediting lock (10 min old) is taken over and credited the recorded 4.00');
$state = dbLoad();
$stl = array_values(array_filter($state['mod_onepaycx_payments'], function ($r) { return $r['payment_id'] === 'pay_stl'; }));
check(count($stl) === 1 && $stl[0]['status'] === 'paid', 'stale-lock row ends up paid');

// Wrong HTTP method / inactive module.
list($code, $out, $entries) = runScenario('notify-get', array('ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_SIG' => $sig), $paidBody);
check(trim($out) === 'method not allowed', 'GET on the notify endpoint -> 405 text');
list($code, $out, $entries) = runScenario('notify-inactive', array('ONEPAYCX_TEST_INACTIVE' => '1', 'ONEPAYCX_TEST_SIG' => $sig), $paidBody);
check(trim($out) === 'Module Not Activated', 'inactive gateway -> "Module Not Activated"');

/* ------------------------------------------------------------ 4. return */

// Buyer returns for invoice 44; our table knows pay_ret; API says paid.
$state = dbLoad();
$state['mod_onepaycx_payments'][] = array('id' => 99, 'invoice_id' => 44, 'payment_id' => 'pay_ret', 'amount' => '25.50', 'currency' => 'USD', 'status' => 'pending', 'checkout_url' => 'https://1pay.cx/pay/pay_ret', 'created_at' => date('Y-m-d H:i:s'));
$state['mod_onepaycx_payments'][] = array('id' => 100, 'invoice_id' => 45, 'payment_id' => 'pay_pen', 'amount' => '7.00', 'currency' => 'USD', 'status' => 'pending', 'checkout_url' => 'https://1pay.cx/pay/pay_pen', 'created_at' => date('Y-m-d H:i:s'));
$state['mod_onepaycx_payments'][] = array('id' => 101, 'invoice_id' => 47, 'payment_id' => 'pay_oth', 'amount' => '7.00', 'currency' => 'USD', 'status' => 'pending', 'checkout_url' => 'https://1pay.cx/pay/pay_oth', 'created_at' => date('Y-m-d H:i:s'));
dbSave($state);


list($code, $out, $entries) = runScenario('return-paid', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=44',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_ret', '44', 26.27))), // API amount carries a surcharge
));
$aip = logOf($entries, 'addInvoicePayment');
check(count($aip) === 1 && $aip[0]['invoiceId'] === 44 && $aip[0]['transId'] === 'pay_ret' && $aip[0]['amount'] === '25.50', 'return: paid payment credited the recorded 25.50, not the surcharged API amount');
check(strpos($out, 'viewinvoice.php?id=44&amp;paymentsuccess=true') !== false, 'return: redirected to the invoice with paymentsuccess');
check(count(httpCalls($entries, 'GET', '/v1/payments/pay_ret')) === 1, 'return: single poll when already paid');

// Coming back a second time (e.g. refresh) must not double-credit.
list($code, $out, $entries) = runScenario('return-again', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=44',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_ret', '44', 25.5))),
));
check(count(logOf($entries, 'addInvoicePayment')) === 0 && strpos($out, 'viewinvoice.php?id=44&amp;paymentsuccess=true') !== false, 'return: revisit does not credit again, goes straight to the paid invoice');
check(count(logOf($entries, 'http')) === 0, 'return: revisit of a paid invoice makes no API call at all');

// Still pending: polled 3 times, then back to the invoice without crediting.
list($code, $out, $entries) = runScenario('return-pending', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=45',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_pen', '45', 7, 'pending'), $paidRow('pay_pen', '45', 7, 'pending'), $paidRow('pay_pen', '45', 7, 'pending'))),
));
check(count(httpCalls($entries, 'GET', '/v1/payments/pay_pen')) === 3, 'return: pending payment polled 3 times');
check(count(logOf($entries, 'addInvoicePayment')) === 0 && preg_match('#viewinvoice\.php\?id=45$#', trim($out)) === 1, 'return: pending -> plain invoice redirect, no credit');

// The API says the payment belongs to another invoice -> never credited here.
list($code, $out, $entries) = runScenario('return-mismatch', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=47',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_oth', '999', 7))),
));
check(count(httpCalls($entries, 'GET', '/v1/payments/pay_oth')) === 1 && count(logOf($entries, 'addInvoicePayment')) === 0 && preg_match('#viewinvoice\.php\?id=47$#', trim($out)) === 1, 'return: reference mismatch is polled but never credited');

// Expired payment -> paymentfailed hint.
list($code, $out, $entries) = runScenario('return-expired', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=47',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_oth', '47', 7, 'expired'))),
));
check(strpos($out, 'id=47&amp;paymentfailed=true') !== false && count(logOf($entries, 'addInvoicePayment')) === 0, 'return: expired -> paymentfailed redirect, no credit');

// No local row for invoice 46: nothing is polled and, above all, the merchant's payment
// list is never consulted — a payment with reference "46" from another store sharing the
// API key (e.g. a WooCommerce order 46) must not be able to pay this invoice.
$state = dbLoad();
$state['tblaccounts'] = array_values(array_filter($state['tblaccounts'], function ($r) { return $r['transid'] !== 'pay_jkl'; }));
dbSave($state);
list($code, $out, $entries) = runScenario('return-norow', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=46',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array(
        array('method' => 'GET', 'path' => '/v1/payments', 'status' => 200, 'body' => array(
            array('id' => 'pay_lst', 'reference' => '46', 'status' => 'paid', 'amount' => 3, 'currency' => 'USD'),
        )),
        $paidRow('pay_lst', '46', 3),
    )),
));
check(count(logOf($entries, 'http')) === 0, 'return: nothing recorded locally -> no API call (payment list is never consulted)');
check(count(logOf($entries, 'addInvoicePayment')) === 0 && preg_match('#viewinvoice\.php\?id=46$#', trim($out)) === 1, 'return: nothing recorded locally -> plain invoice redirect, no credit');

// "Convert To" currency: amount converted back into the invoice currency.
$state = dbLoad();
$state['mod_onepaycx_payments'][] = array('id' => 102, 'invoice_id' => 45, 'payment_id' => 'pay_cnv', 'amount' => '7.00', 'currency' => 'EUR', 'status' => 'pending', 'checkout_url' => 'https://1pay.cx/pay/pay_cnv', 'created_at' => date('Y-m-d H:i:s'));
dbSave($state);

// First: the API row's currency does not match the recorded one -> refused, not credited.
list($code, $out, $entries) = runScenario('return-currency-mismatch', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=45', 'ONEPAYCX_TEST_CONVERTTO' => '2',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_cnv', '45', 7, 'paid', 'USD'), $paidRow('pay_pen', '45', 7, 'pending'))),
));
check(count(logOf($entries, 'addInvoicePayment')) === 0 && strpos($out, 'paymentsuccess') === false, 'return: recorded EUR but API says USD -> refused, no credit');
check(count(logOf($entries, 'logTransaction')) === 1 && logOf($entries, 'logTransaction')[0]['result'] === 'Unsuccessful', 'return: currency mismatch logged as Unsuccessful');
$state = dbLoad();
$cnv = array_values(array_filter($state['mod_onepaycx_payments'], function ($r) { return $r['payment_id'] === 'pay_cnv'; }));
check(count($cnv) === 1 && $cnv[0]['status'] === 'pending', 'return: refused row stays pending (not locked)');

list($code, $out, $entries) = runScenario('return-convertto', array(
    'ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=45', 'ONEPAYCX_TEST_CONVERTTO' => '2',
    'ONEPAYCX_TEST_TRANSPORT' => json_encode(array($paidRow('pay_cnv', '45', 7.07, 'paid', 'EUR'), $paidRow('pay_pen', '45', 7, 'pending'))),
));
$aip = logOf($entries, 'addInvoicePayment');
$cc = logOf($entries, 'convertCurrency');
check(count($cc) === 1 && $cc[0]['from'] === 2 && $cc[0]['to'] === 1, 'convertto: convertCurrency(gateway currency -> client currency)');
check(count($aip) === 1 && $aip[0]['amount'] === '14.00', 'convertto: credited the converted recorded amount (7.00 EUR -> 14.00)');

// Bad input.
list($code, $out, $entries) = runScenario('return-noinvoice', array('ONEPAYCX_TEST_METHOD' => 'GET', 'ONEPAYCX_TEST_QUERY' => 'action=return&invoiceid=abc'));
check(strpos($out, 'clientarea.php?action=invoices') !== false, 'return: invalid invoice id -> invoices list');

/* ------------------------------------------------------------ summary */

echo "\n$checks checks, $failures failed\n";
if ($failures === 0) {
    rrmdir($root);
    echo "PASS\n";
    exit(0);
}
echo "FAIL (fake root kept at $root)\n";
exit(1);
