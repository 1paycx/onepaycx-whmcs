<?php
/**
 * Stub WHMCS environment for the compile/behaviour harness.
 *
 * Defines, as no-op or recording stubs, everything the 1pay.cx gateway module
 * and its callback call from WHMCS:
 *   - the WHMCS constant and the InvalidConfiguration exception class
 *   - getGatewayVariables, checkCbInvoiceID, checkCbTransID, logTransaction,
 *     addInvoicePayment, encrypt, decrypt, convertCurrency
 *   - \WHMCS\Database\Capsule with a tiny JSON-backed query builder
 * plus a canned HTTP transport so no request ever leaves the machine.
 *
 * Recorded calls are appended as JSON lines to ONEPAYCX_TEST_LOG so the runner
 * (test/run.php) can assert on them from outside the subprocess.
 *
 * @license MIT
 */

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    function onepaycx_test_log($kind, array $payload = array())
    {
        $file = getenv('ONEPAYCX_TEST_LOG');
        if ($file) {
            file_put_contents($file, json_encode(array('kind' => $kind) + $payload) . "\n", FILE_APPEND);
        }
    }
}

/* ---------------------------------------------------------------- WHMCS API */

namespace WHMCS\Exception\Module {
    class InvalidConfiguration extends \Exception
    {
    }
}

namespace WHMCS\Database {
    /** JSON-file-backed stand-in for Illuminate's query builder — just enough for the module. */
    class Capsule
    {
        public static $data = null;

        public static function load()
        {
            if (self::$data !== null) {
                return;
            }
            $file = getenv('ONEPAYCX_TEST_DB');
            self::$data = array();
            if ($file && is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    self::$data = $decoded;
                }
            }
            register_shutdown_function(function () {
                $file = getenv('ONEPAYCX_TEST_DB');
                if ($file) {
                    file_put_contents($file, json_encode(Capsule::$data, JSON_PRETTY_PRINT));
                }
            });
        }

        public static function table($name)
        {
            self::load();
            if (!isset(self::$data[$name]) && !isset(self::$data['__schema'][$name])) {
                throw new \RuntimeException('no such table: ' . $name);
            }
            return new FakeBuilder($name);
        }

        public static function schema()
        {
            self::load();
            return new FakeSchema();
        }
    }

    class FakeSchema
    {
        public function hasTable($name)
        {
            return isset(Capsule::$data['__schema'][$name]);
        }

        public function create($name, $callback)
        {
            $bp = new FakeBlueprint();
            $callback($bp);
            Capsule::$data['__schema'][$name] = $bp->columns;
            if (!isset(Capsule::$data[$name])) {
                Capsule::$data[$name] = array();
            }
            \onepaycx_test_log('schema.create', array('table' => $name, 'columns' => $bp->columns));
        }
    }

    class FakeBlueprint
    {
        public $columns = array();

        public function __call($method, $args)
        {
            $this->columns[] = $method . '(' . implode(',', array_map('strval', $args)) . ')';
            return $this;
        }
    }

    class FakeBuilder
    {
        private $table;
        private $wheres = array();
        private $order = null;
        private $limit = null;

        public function __construct($table)
        {
            $this->table = $table;
        }

        public function where($col, $op, $val = null)
        {
            if (func_num_args() === 2) {
                $val = $op;
                $op = '=';
            }
            $this->wheres[] = array($col, $op, $val);
            return $this;
        }

        public function orderBy($col, $dir = 'asc')
        {
            $this->order = array($col, strtolower($dir));
            return $this;
        }

        public function limit($n)
        {
            $this->limit = (int) $n;
            return $this;
        }

        private function rows()
        {
            $rows = isset(Capsule::$data[$this->table]) ? Capsule::$data[$this->table] : array();
            $out = array();
            foreach ($rows as $idx => $row) {
                foreach ($this->wheres as $w) {
                    list($col, $op, $val) = $w;
                    $have = isset($row[$col]) ? $row[$col] : null;
                    $match = ($op === '=' || $op === '==') ? ((string) $have === (string) $val)
                        : (($op === '!=' || $op === '<>') ? ((string) $have !== (string) $val) : false);
                    if (!$match) {
                        continue 2;
                    }
                }
                $out[$idx] = $row;
            }
            if ($this->order) {
                list($col, $dir) = $this->order;
                uasort($out, function ($a, $b) use ($col, $dir) {
                    $cmp = strnatcmp((string) (isset($a[$col]) ? $a[$col] : ''), (string) (isset($b[$col]) ? $b[$col] : ''));
                    return $dir === 'desc' ? -$cmp : $cmp;
                });
            }
            if ($this->limit !== null) {
                $out = array_slice($out, 0, $this->limit, true);
            }
            return $out;
        }

        public function get()
        {
            $rows = array();
            foreach ($this->rows() as $row) {
                $rows[] = (object) $row;
            }
            return $rows;
        }

        public function first()
        {
            $rows = $this->rows();
            return $rows ? (object) reset($rows) : null;
        }

        public function value($col)
        {
            $row = $this->first();
            return ($row && isset($row->$col)) ? $row->$col : null;
        }

        public function exists()
        {
            return count($this->rows()) > 0;
        }

        public function insert(array $row)
        {
            if (!isset(Capsule::$data[$this->table])) {
                Capsule::$data[$this->table] = array();
            }
            $row['id'] = count(Capsule::$data[$this->table]) + 1;
            Capsule::$data[$this->table][] = $row;
            \onepaycx_test_log('db.insert', array('table' => $this->table, 'row' => $row));
            return true;
        }

        public function update(array $values)
        {
            $n = 0;
            foreach (array_keys($this->rows()) as $idx) {
                Capsule::$data[$this->table][$idx] = array_merge(Capsule::$data[$this->table][$idx], $values);
                $n++;
            }
            \onepaycx_test_log('db.update', array('table' => $this->table, 'values' => $values, 'rows' => $n));
            return $n;
        }
    }
}

namespace {
    function getGatewayVariables($module)
    {
        onepaycx_test_log('getGatewayVariables', array('module' => $module));
        return array(
            'name'          => '1pay.cx',
            'type'          => getenv('ONEPAYCX_TEST_INACTIVE') ? '' : 'Invoices',
            'paymentmethod' => $module,
            'systemurl'     => 'https://billing.example.test/',
            'convertto'     => (string) getenv('ONEPAYCX_TEST_CONVERTTO'),
            'apiKey'        => (string) getenv('ONEPAYCX_TEST_APIKEY'),
            'method'        => '',
            'buttonText'    => 'Pay with 1pay.cx',
        );
    }

    function checkCbInvoiceID($invoiceId, $gatewayName)
    {
        onepaycx_test_log('checkCbInvoiceID', array('invoiceId' => $invoiceId, 'gateway' => $gatewayName));
        return (int) $invoiceId;
    }

    function checkCbTransID($transId)
    {
        onepaycx_test_log('checkCbTransID', array('transId' => $transId));
        foreach (\WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $transId)->get() as $row) {
            echo 'Transaction ID already exists';
            exit;
        }
    }

    function logTransaction($gatewayName, $data, $result)
    {
        onepaycx_test_log('logTransaction', array('gateway' => $gatewayName, 'result' => $result, 'data' => $data));
    }

    function addInvoicePayment($invoiceId, $transId, $amount, $fees, $gateway)
    {
        onepaycx_test_log('addInvoicePayment', array(
            'invoiceId' => $invoiceId, 'transId' => $transId, 'amount' => $amount, 'fees' => $fees, 'gateway' => $gateway,
        ));
        \WHMCS\Database\Capsule::table('tblaccounts')->insert(array(
            'invoiceid' => $invoiceId, 'transid' => $transId, 'amountin' => $amount, 'gateway' => $gateway,
        ));
        return true;
    }

    function encrypt($string)
    {
        return base64_encode('stub|' . $string);
    }

    function decrypt($string)
    {
        $plain = base64_decode($string, true);
        return (is_string($plain) && strpos($plain, 'stub|') === 0) ? substr($plain, 5) : '';
    }

    function convertCurrency($amount, $from, $to)
    {
        onepaycx_test_log('convertCurrency', array('amount' => $amount, 'from' => $from, 'to' => $to));
        return $amount * 2; // deliberately obvious factor for the assertions
    }

    /**
     * Install the canned transport. ONEPAYCX_TEST_TRANSPORT is a JSON list of
     * { method, path, status, body } consumed in order; each request is logged.
     */
    function onepaycx_test_install_transport()
    {
        $canned = json_decode((string) getenv('ONEPAYCX_TEST_TRANSPORT'), true);
        $GLOBALS['onepaycx_test_canned'] = is_array($canned) ? $canned : array();
        OnePayCx_Whmcs_Client::$transport = function ($method, $url, array $headers, $body) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $host = (string) parse_url($url, PHP_URL_HOST);
            $hasBearer = false;
            $ua = '';
            foreach ($headers as $h) {
                if (stripos($h, 'Authorization: Bearer ') === 0) {
                    $hasBearer = true;
                }
                if (stripos($h, 'User-Agent: ') === 0) {
                    $ua = substr($h, 12);
                }
            }
            onepaycx_test_log('http', array(
                'method' => $method, 'host' => $host, 'path' => $path, 'bearer' => $hasBearer, 'ua' => $ua,
                'body' => $body === null ? null : json_decode($body, true),
            ));
            foreach ($GLOBALS['onepaycx_test_canned'] as $i => $c) {
                if ($c['method'] === $method && $c['path'] === $path) {
                    unset($GLOBALS['onepaycx_test_canned'][$i]);
                    return array('status' => (int) $c['status'], 'body' => is_string($c['body']) ? $c['body'] : json_encode($c['body']));
                }
            }
            return array('status' => 0, 'body' => '', 'error' => 'no canned response for ' . $method . ' ' . $path);
        };
    }
}
