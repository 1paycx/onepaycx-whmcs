<?php
/**
 * 1pay.cx for WHMCS — persistence helpers.
 *
 * Two things live in the WHMCS database:
 *
 *  1. `mod_onepaycx_payments` — one row per payment created for an invoice: the
 *     amount and currency the plugin asked 1pay.cx to charge (the figure an
 *     invoice is credited with — never the API's, which may carry a customer
 *     surcharge), the open checkout to reuse on re-render, the payment ids the
 *     buyer's return trip may poll, and a `crediting` lock that makes crediting
 *     atomic when the notify and the return trip race. Created on first use; if
 *     the table cannot be created only the signed notify credits invoices.
 *
 *  2. Extra rows in `tblpaymentgateways` for this gateway — the merchant's webhook
 *     signing secret (obtained once from GET /v1/me), encrypted with WHMCS's own
 *     encrypt() when available, plus a fingerprint of the API key it belongs to and
 *     the time it was fetched. Nothing here is ever logged.
 *
 * Every method is best-effort: database trouble degrades to "not cached" rather
 * than breaking a payment.
 *
 * @copyright 1pay.cx
 * @license   MIT
 */

if (class_exists('OnePayCx_Whmcs_Store', false)) {
    return;
}

class OnePayCx_Whmcs_Store
{
    const TABLE = 'mod_onepaycx_payments';

    /** Reuse an open checkout for at most this long (1pay.cx links expire after 24h). */
    const REUSE_SECONDS = 82800; // 23h

    /** Don't hit GET /v1/me for a fresh secret more often than this. */
    const SECRET_REFRESH_SECONDS = 60;

    /**
     * A row left in `crediting` longer than this (a request died between claiming
     * the row and addInvoicePayment) is treated as abandoned and may be re-claimed.
     */
    const CREDIT_LOCK_SECONDS = 120;

    private static $tableReady = null;

    /** True when WHMCS's database layer is loaded. */
    public static function available()
    {
        return class_exists('\\WHMCS\\Database\\Capsule');
    }

    private static function table($name)
    {
        return \WHMCS\Database\Capsule::table($name);
    }

    /* ------------------------------------------------------------------ payments */

    public static function ensureTable()
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        self::$tableReady = false;
        if (!self::available()) {
            return false;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, function ($t) {
                    $t->increments('id');
                    $t->integer('invoice_id');
                    $t->string('payment_id', 64);
                    $t->decimal('amount', 16, 2);
                    $t->string('currency', 3);
                    $t->string('status', 16)->default('pending');
                    $t->string('checkout_url', 255)->nullable();
                    $t->dateTime('created_at');
                    $t->dateTime('updated_at')->nullable();
                    $t->index('invoice_id');
                    $t->unique('payment_id');
                });
            }
            self::$tableReady = true;
        } catch (\Throwable $e) {
            self::$tableReady = false;
        }
        return self::$tableReady;
    }

    /**
     * A still-open payment for this invoice with the same amount and currency, or
     * null. Returns ['payment_id' => ..., 'checkout_url' => ...].
     */
    public static function findReusable($invoiceId, $amount, $currency)
    {
        if (!self::ensureTable()) {
            return null;
        }
        try {
            $rows = self::table(self::TABLE)
                ->where('invoice_id', (int) $invoiceId)
                ->where('status', 'pending')
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get();
            $cutoff = time() - self::REUSE_SECONDS;
            foreach ($rows as $row) {
                $row = (array) $row;
                if (number_format((float) $row['amount'], 2, '.', '') !== number_format((float) $amount, 2, '.', '')) {
                    continue;
                }
                if (strtoupper((string) $row['currency']) !== strtoupper((string) $currency)) {
                    continue;
                }
                if (empty($row['checkout_url']) || strtotime((string) $row['created_at']) < $cutoff) {
                    continue;
                }
                return array('payment_id' => (string) $row['payment_id'], 'checkout_url' => (string) $row['checkout_url']);
            }
        } catch (\Throwable $e) {
            // fall through: caller creates a fresh payment
        }
        return null;
    }

    public static function recordPayment($invoiceId, $paymentId, $amount, $currency, $checkoutUrl)
    {
        if (!self::ensureTable()) {
            return false;
        }
        try {
            self::table(self::TABLE)->insert(array(
                'invoice_id'   => (int) $invoiceId,
                'payment_id'   => (string) $paymentId,
                'amount'       => number_format((float) $amount, 2, '.', ''),
                'currency'     => strtoupper((string) $currency),
                'status'       => 'pending',
                'checkout_url' => (string) $checkoutUrl,
                'created_at'   => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The row recorded for a payment id, or null when there is none (or the table
     * is unavailable). Keys: invoice_id (int), amount ("x.xx"), currency (upper-case
     * ISO code), status, updated_at (string|null).
     */
    public static function findByPaymentId($paymentId)
    {
        if (!self::ensureTable() || !OnePayCx_Whmcs_Client::isPaymentId($paymentId)) {
            return null;
        }
        try {
            $row = self::table(self::TABLE)->where('payment_id', (string) $paymentId)->first();
            if (!$row) {
                return null;
            }
            $row = (array) $row;
            return array(
                'invoice_id' => (int) $row['invoice_id'],
                'amount'     => number_format((float) $row['amount'], 2, '.', ''),
                'currency'   => strtoupper((string) $row['currency']),
                'status'     => (string) $row['status'],
                'updated_at' => (isset($row['updated_at']) && $row['updated_at'] !== null && $row['updated_at'] !== '') ? (string) $row['updated_at'] : null,
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The newest payment id already credited to an invoice, or '' when none. */
    public static function paidPaymentId($invoiceId)
    {
        if (!self::ensureTable()) {
            return '';
        }
        try {
            $row = self::table(self::TABLE)
                ->where('invoice_id', (int) $invoiceId)
                ->where('status', 'paid')
                ->orderBy('id', 'desc')
                ->first();
            if ($row && OnePayCx_Whmcs_Client::isPaymentId((string) $row->payment_id)) {
                return (string) $row->payment_id;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    /**
     * Claim a payment for crediting: an atomic compare-and-set that moves the row
     * from its current status to `crediting`, so that of two requests crediting the
     * same payment at once (the signed notify and the buyer's return trip routinely
     * arrive within the same second) exactly one proceeds.
     *
     * @return bool|null true = claimed, credit it; false = already paid or being
     *                   credited by another request; null = no row / table
     *                   unavailable (caller falls back to its own duplicate checks)
     */
    public static function claimForCredit($paymentId)
    {
        if (!self::ensureTable() || !OnePayCx_Whmcs_Client::isPaymentId($paymentId)) {
            return null;
        }
        try {
            $row = self::table(self::TABLE)->where('payment_id', (string) $paymentId)->first();
            if (!$row) {
                return null;
            }
            $status = (string) $row->status;
            $updatedAt = (isset($row->updated_at) && $row->updated_at !== null && $row->updated_at !== '') ? (string) $row->updated_at : null;
            if ($status === 'paid') {
                return false;
            }
            if ($status === 'crediting') {
                $at = $updatedAt !== null ? (int) strtotime($updatedAt) : 0;
                if ($at > 0 && time() - $at < self::CREDIT_LOCK_SECONDS) {
                    return false; // another request is crediting it right now
                }
                // Stale lock: the earlier request died mid-credit; take it over.
            }
            $affected = self::table(self::TABLE)
                ->where('payment_id', (string) $paymentId)
                ->where('status', $status)
                ->where('updated_at', $updatedAt)
                ->update(array('status' => 'crediting', 'updated_at' => date('Y-m-d H:i:s')));
            return (int) $affected === 1;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Payment ids created for an invoice that are not yet marked paid, newest first (max 5). */
    public static function openPaymentIds($invoiceId)
    {
        if (!self::ensureTable()) {
            return array();
        }
        $ids = array();
        try {
            $rows = self::table(self::TABLE)
                ->where('invoice_id', (int) $invoiceId)
                ->where('status', '!=', 'paid')
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get();
            foreach ($rows as $row) {
                $row = (array) $row;
                if (OnePayCx_Whmcs_Client::isPaymentId($row['payment_id'])) {
                    $ids[] = (string) $row['payment_id'];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $ids;
    }

    public static function markStatus($paymentId, $status)
    {
        if (!self::ensureTable()) {
            return;
        }
        try {
            self::table(self::TABLE)
                ->where('payment_id', (string) $paymentId)
                ->update(array('status' => (string) $status, 'updated_at' => date('Y-m-d H:i:s')));
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /* ------------------------------------------------------------- WHMCS lookups */

    /** True/false when the check succeeded, null when it could not be performed. */
    public static function transactionExists($transId)
    {
        if (!self::available()) {
            return null;
        }
        try {
            return (bool) self::table('tblaccounts')->where('transid', (string) $transId)->exists();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The upper-case ISO code of a WHMCS currency id, or '' when unknown. */
    public static function currencyCode($currencyId)
    {
        if (!self::available() || (int) $currencyId <= 0) {
            return '';
        }
        try {
            $code = self::table('tblcurrencies')->where('id', (int) $currencyId)->value('code');
            $code = strtoupper(trim((string) $code));
            return strlen($code) === 3 ? $code : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The WHMCS currency id for an ISO code, or 0 when unknown. */
    public static function currencyIdByCode($code)
    {
        $code = strtoupper(trim((string) $code));
        if (!self::available() || strlen($code) !== 3) {
            return 0;
        }
        try {
            return (int) self::table('tblcurrencies')->where('code', $code)->value('id');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The currency id of the client an invoice belongs to, or 0 when unknown. */
    public static function invoiceCurrencyId($invoiceId)
    {
        if (!self::available()) {
            return 0;
        }
        try {
            $userId = (int) self::table('tblinvoices')->where('id', (int) $invoiceId)->value('userid');
            if ($userId <= 0) {
                return 0;
            }
            return (int) self::table('tblclients')->where('id', $userId)->value('currency');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* --------------------------------------------------------- gateway settings */

    public static function getSetting($name)
    {
        if (!self::available()) {
            return null;
        }
        try {
            $value = self::table('tblpaymentgateways')
                ->where('gateway', ONEPAYCX_GATEWAY)
                ->where('setting', (string) $name)
                ->value('value');
            return $value === null ? null : (string) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function setSetting($name, $value)
    {
        if (!self::available()) {
            return false;
        }
        try {
            $q = self::table('tblpaymentgateways')->where('gateway', ONEPAYCX_GATEWAY)->where('setting', (string) $name);
            if ($q->exists()) {
                self::table('tblpaymentgateways')
                    ->where('gateway', ONEPAYCX_GATEWAY)
                    ->where('setting', (string) $name)
                    ->update(array('value' => (string) $value));
            } else {
                self::table('tblpaymentgateways')->insert(array(
                    'gateway' => ONEPAYCX_GATEWAY,
                    'setting' => (string) $name,
                    'value'   => (string) $value,
                    'order'   => 0,
                ));
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ----------------------------------------------------------- webhook secret */

    /** Short fingerprint tying a cached secret to the API key it was fetched with. */
    private static function keyFingerprint($apiKey)
    {
        return substr(hash('sha256', 'onepaycx-whmcs:' . (string) $apiKey), 0, 16);
    }

    private static function protect($secret)
    {
        if (function_exists('encrypt')) {
            try {
                $enc = encrypt($secret);
                if (is_string($enc) && $enc !== '') {
                    return 'enc:' . $enc;
                }
            } catch (\Throwable $e) {
                // fall back to plain storage
            }
        }
        return 'raw:' . $secret;
    }

    private static function unprotect($stored)
    {
        $stored = (string) $stored;
        if (strpos($stored, 'raw:') === 0) {
            return substr($stored, 4);
        }
        if (strpos($stored, 'enc:') === 0) {
            if (!function_exists('decrypt')) {
                return '';
            }
            try {
                $plain = decrypt(substr($stored, 4));
                return is_string($plain) ? $plain : '';
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }

    /**
     * The merchant's webhook signing secret for $apiKey. Served from the cache in
     * tblpaymentgateways when it belongs to this key; otherwise (or when $refresh
     * is requested and the throttle allows) fetched from GET /v1/me and cached.
     *
     * @return string '' when unavailable
     */
    public static function webhookSecret($apiKey, $refresh = false)
    {
        $apiKey = trim((string) $apiKey);
        if ($apiKey === '') {
            return '';
        }
        $fp = self::keyFingerprint($apiKey);

        $cached = '';
        $cachedFp = self::getSetting('webhookSecretKey');
        if ($cachedFp === $fp) {
            $cached = self::unprotect(self::getSetting('webhookSecret'));
        }

        if ($cached !== '' && !$refresh) {
            return $cached;
        }
        if ($cached !== '' && $refresh) {
            $at = (int) self::getSetting('webhookSecretAt');
            if ($at > 0 && time() - $at < self::SECRET_REFRESH_SECONDS) {
                return $cached; // throttled: keep what we have
            }
        }

        $client = new OnePayCx_Whmcs_Client($apiKey);
        $res = $client->me();
        if (!$res['ok'] || !is_array($res['data']) || empty($res['data']['webhookSecret']) || !is_string($res['data']['webhookSecret'])) {
            return $cached;
        }
        $secret = $res['data']['webhookSecret'];
        self::cacheWebhookSecret($apiKey, $secret);
        return $secret;
    }

    /** Remember a secret obtained from GET /v1/me, tied to the API key it came with. */
    public static function cacheWebhookSecret($apiKey, $secret)
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return false;
        }
        $ok = self::setSetting('webhookSecret', self::protect($secret));
        self::setSetting('webhookSecretKey', self::keyFingerprint(trim((string) $apiKey)));
        self::setSetting('webhookSecretAt', (string) time());
        return $ok;
    }
}
