<?php
/**
 * 1pay.cx for WHMCS — tiny HTTP client for the 1pay.cx REST API.
 *
 * Talks to https://api.1pay.cx only, never follows redirects, and never logs
 * or echoes the API key or the webhook secret.
 *
 * @copyright 1pay.cx
 * @license   MIT
 */

if (class_exists('OnePayCx_Whmcs_Client', false)) {
    return;
}

class OnePayCx_Whmcs_Client
{
    /**
     * Test seam. When set, used instead of cURL. Signature:
     *   function (string $method, string $url, array $headers, ?string $body): array{status:int, body:string}
     * The harness installs a canned transport here; production leaves it null.
     *
     * @var callable|null
     */
    public static $transport = null;

    /** @var string */
    private $key = '';

    public function __construct($key)
    {
        $this->key = trim((string) $key);
    }

    /** GET /v1/me — validates the key and returns { id, email, webhookSecret, ... }. */
    public function me()
    {
        return $this->request('GET', '/v1/me');
    }

    /** POST /v1/payments — create a hosted-checkout payment. Returns { id, checkoutUrl }. */
    public function createPayment(array $body)
    {
        return $this->request('POST', '/v1/payments', $body);
    }

    /** GET /v1/payments/{id} — status is one of pending | paid | failed | expired. */
    public function getPayment($id)
    {
        if (!self::isPaymentId($id)) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'invalid payment id');
        }
        return $this->request('GET', '/v1/payments/' . rawurlencode($id));
    }

    /** Payment ids are short opaque tokens; anything else never reaches the wire. */
    public static function isPaymentId($id)
    {
        return is_string($id) && $id !== '' && strlen($id) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
    }

    /**
     * Resolve the `checkoutUrl` returned by POST /v1/payments to an absolute URL on
     * https://1pay.cx. A relative path is appended to the checkout host; an absolute
     * URL is only accepted when it already points at 1pay.cx. Anything else yields
     * '' so the buyer is never sent to an unexpected host.
     */
    public static function checkoutUrl($path)
    {
        $path = (string) $path;
        if ($path === '') {
            return '';
        }
        if ($path[0] === '/') {
            return ONEPAYCX_CHECKOUT_BASE . $path;
        }
        $host = parse_url($path, PHP_URL_HOST);
        $scheme = parse_url($path, PHP_URL_SCHEME);
        if ($scheme === 'https' && is_string($host) && strtolower($host) === strtolower((string) parse_url(ONEPAYCX_CHECKOUT_BASE, PHP_URL_HOST))) {
            return $path;
        }
        return '';
    }

    /**
     * @return array{ok:bool, status:int, data:mixed, error:string}
     */
    private function request($method, $path, $body = null)
    {
        if ($this->key === '') {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'API key is not configured');
        }

        $url = ONEPAYCX_API_BASE . $path;
        $headers = array(
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: onepaycx-whmcs/' . ONEPAYCX_WHMCS_VERSION,
        );
        $json = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $json = json_encode($body);
            if ($json === false) {
                return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'could not encode request');
            }
        }

        try {
            if (self::$transport !== null) {
                $raw = call_user_func(self::$transport, $method, $url, $headers, $json);
            } else {
                $raw = $this->curl($method, $url, $headers, $json);
            }
        } catch (\Throwable $e) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'network error');
        }

        $status = isset($raw['status']) ? (int) $raw['status'] : 0;
        $data = json_decode(isset($raw['body']) ? (string) $raw['body'] : '', true);
        $ok = $status >= 200 && $status < 300;
        $error = '';
        if (!$ok) {
            if ($status === 0) {
                $error = isset($raw['error']) && $raw['error'] !== '' ? (string) $raw['error'] : 'network error';
            } elseif (is_array($data) && isset($data['error']) && is_string($data['error'])) {
                $error = $data['error'];
            } else {
                $error = 'HTTP ' . $status;
            }
        }

        return array('ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error);
    }

    /**
     * @return array{status:int, body:string, error:string}
     */
    private function curl($method, $url, array $headers, $json)
    {
        if (!function_exists('curl_init')) {
            return array('status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not installed');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,   // never leave api.1pay.cx
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ));
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $errno !== 0) {
            // curl_error() can include the URL; it never includes headers, so no key leaks.
            return array('status' => 0, 'body' => '', 'error' => 'network error (curl ' . $errno . ')');
        }
        return array('status' => $status, 'body' => (string) $body, 'error' => '');
    }
}
