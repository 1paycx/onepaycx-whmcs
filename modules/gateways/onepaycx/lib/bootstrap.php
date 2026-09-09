<?php
/**
 * 1pay.cx for WHMCS — shared bootstrap.
 *
 * Loaded by both the gateway module (modules/gateways/onepaycx.php) and its
 * callback (modules/gateways/callback/onepaycx.php). Defines the constants and
 * pulls in the two helper classes. Safe to include more than once.
 *
 * All outbound traffic goes to https://api.1pay.cx and https://1pay.cx only.
 *
 * @copyright 1pay.cx
 * @license   MIT
 */

if (!defined('ONEPAYCX_WHMCS_VERSION')) {
    define('ONEPAYCX_WHMCS_VERSION', '1.0.0');
}
if (!defined('ONEPAYCX_API_BASE')) {
    define('ONEPAYCX_API_BASE', 'https://api.1pay.cx');   // REST API host (calls /v1/...)
}
if (!defined('ONEPAYCX_CHECKOUT_BASE')) {
    define('ONEPAYCX_CHECKOUT_BASE', 'https://1pay.cx');  // hosted checkout host (/pay/<id>)
}
if (!defined('ONEPAYCX_GATEWAY')) {
    define('ONEPAYCX_GATEWAY', 'onepaycx');
}

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Store.php';
