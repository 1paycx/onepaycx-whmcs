# 1pay.cx for WHMCS

Accept card, Bitcoin and Monero payments on WHMCS invoices with [1pay.cx](https://1pay.cx).
Buyers pay on the hosted 1pay.cx checkout; you settle in crypto. Paste your API key and go live.

* Version 1.0.0 — third-party gateway module for **WHMCS 8.x** on **PHP 8.1+** (cURL required).
* Adds a **Pay with 1pay.cx** button to every unpaid invoice.
* Invoices are marked paid automatically by a signed webhook — no manual reconciliation.
* The buyer's return trip also checks the payment, so the invoice updates even if the webhook is delayed.
* No card data ever touches your server. All traffic goes to `https://api.1pay.cx` and `https://1pay.cx` only.
* Licence: MIT (see `LICENSE`).

## Files

```
modules/gateways/onepaycx.php                 gateway module (MetaData, config, config_validate, link)
modules/gateways/onepaycx/lib/bootstrap.php   constants + class loading (shared by module and callback)
modules/gateways/onepaycx/lib/Client.php      HTTP client for api.1pay.cx
modules/gateways/onepaycx/lib/Store.php       DB helpers: payment table, cached webhook secret
modules/gateways/callback/onepaycx.php        webhook receiver + buyer-return handler
test/                                         stub-WHMCS compile & behaviour harness (not deployed)
```

## Install

1. Copy the contents of `modules/` into your WHMCS installation so that you have:
   * `<whmcs>/modules/gateways/onepaycx.php`
   * `<whmcs>/modules/gateways/onepaycx/lib/…`
   * `<whmcs>/modules/gateways/callback/onepaycx.php`
2. In the WHMCS admin area go to **Setup → Payments → Payment Gateways → All Payment Gateways**
   (WHMCS 8: **System Settings → Payment Gateways**) and activate **1pay.cx**.
3. Paste your **API key** (from your 1pay.cx dashboard: **Settings → API keys**, it looks like `pk_xxx.yyy`) and save.
   The key is verified against 1pay.cx as you save; a rejected key blocks the save with a message.
   The webhook signing secret is fetched and stored automatically — there is nothing to configure on the 1pay.cx side.
4. Optional settings:
   * **Payment method** — let the buyer choose on the 1pay.cx checkout (default), or send them straight to card, Bitcoin or Monero.
   * **Button text** — label of the invoice button (default "Pay with 1pay.cx").
5. Open an unpaid invoice in the client area: it now shows the **Pay with 1pay.cx** button.

Make sure **Setup → General Settings → System URL** is correct and reachable from the internet — the
webhook (`<System URL>/modules/gateways/callback/onepaycx.php`) is delivered to it.

## How it works

1. When an unpaid invoice is viewed, the module creates a payment on 1pay.cx
   (`POST https://api.1pay.cx/v1/payments` with the invoice amount, currency and id as `reference`)
   and renders a button-styled link to `https://1pay.cx{checkoutUrl}` (a link, not a form: a GET form would
   drop the `?method=` that 1pay.cx appends when a payment method is preset). The payment id, amount and
   currency are remembered in the `mod_onepaycx_payments` table (created on first use), so re-rendering the
   invoice reuses the open checkout instead of creating a new payment each time (for up to 23 hours; 1pay.cx
   links expire after 24).
2. The buyer pays by card, Bitcoin or Monero on the hosted checkout.
3. 1pay.cx POSTs `{ "event": "payment.paid", "data": { "paymentId", "reference", "amount", … }, "at" }`
   to the callback with an `X-1pay-Signature` header (hex HMAC-SHA256 of the raw body under your webhook
   secret). The callback verifies the signature with `hash_equals`, then runs the standard WHMCS sequence:
   `checkCbInvoiceID` → `checkCbTransID` → `logTransaction` → `addInvoicePayment` (transaction id = the
   1pay.cx payment id). Repeated deliveries are recognised and acknowledged without crediting twice.
4. The checkout also sends the buyer back to `…/callback/onepaycx.php?action=return&invoiceid=N`. If the
   webhook has already credited the invoice the buyer goes straight to it. Otherwise the handler polls
   `GET /v1/payments/{id}` for the open payment(s) *this module created* for that invoice (up to three times,
   one second apart), credits the invoice if the payment is `paid`, and redirects to the invoice. With no
   local record there is nothing to poll — the merchant's payment list is never consulted, so a payment from
   another integration sharing the API key (whose `reference` may collide with a WHMCS invoice id) can never
   pay a WHMCS invoice.

Payment statuses are `pending`, `paid`, `failed` and `expired`. Only `paid` credits an invoice.

### What is credited

An invoice is credited with the amount the module asked 1pay.cx to charge — the figure stored in
`mod_onepaycx_payments` when the payment was created — never the API's `amount`, which includes any
**customer surcharge** configured on the 1pay.cx side (a merchant with a 3% surcharge invoicing 10.00 sees a
10.30 payment; the invoice is credited 10.00). The API row's currency must match the recorded one, otherwise
nothing is credited and the Gateway Log shows `Unsuccessful` with `currency mismatch`. Only when no local
record exists (the table could not be created) is the API amount used, and then still only if its currency
matches the invoice's expected currency.

Crediting is serialised through the payment row: it is moved to `crediting` with an atomic compare-and-set
before `addInvoicePayment`, so the webhook and the buyer's return trip — which routinely arrive within the
same second — cannot both credit the same payment. A row left in `crediting` for more than two minutes (a
request died mid-credit) is taken over by the next attempt.

### Currency

The invoice amount is sent as a two-decimal number in the invoice currency (ISO code, upper-case). If the
gateway has **Convert To** set in WHMCS, the buyer is charged in that currency and the paid amount is
converted back into the client's currency with WHMCS's `convertCurrency()` before it is applied to the invoice.
The conversion uses the currency the payment was created in (from the module's own record), so changing
**Convert To** while a checkout is open still converts from the right currency.

### Security notes

* The signature is checked over the raw request body, in constant time, before anything is parsed or acted on.
* The webhook secret is stored in `tblpaymentgateways` (encrypted with WHMCS's `encrypt()` when available),
  tied to a fingerprint of the API key so a changed key triggers a refetch. If a signature does not verify,
  the secret is refetched from `GET /v1/me` at most once a minute (covers rotation) and re-checked.
* The API key and the secret are never written to the WHMCS Gateway Log or anywhere else.
* The buyer-return handler never trusts query parameters for money: the payment status and invoice
  reference are read from the 1pay.cx API server-to-server, only for payment ids this module created, and
  the amount credited is the module's own record.
* Outbound requests go to `https://api.1pay.cx` only, with TLS verification and no redirect following;
  the checkout URL is only accepted on `https://1pay.cx`.

### Refunds

Refunds are not supported through this module; issue them from your usual process.

## Troubleshooting

* **Billing → Gateway Log** (WHMCS 8: **Billing → Gateway Log**) shows every webhook that was accepted
  (`Successful`) or rejected (`Unsuccessful: invalid signature`).
* Button shows "could not start the payment": the API rejected the request — the message includes the
  API's error string. Check the key and that the invoice currency is a 3-letter ISO code.
* Invoice stays unpaid after the buyer paid: confirm the System URL is publicly reachable and that nothing
  (WAF, Cloudflare rules, basic auth) blocks POSTs to `/modules/gateways/callback/onepaycx.php`. Opening the
  invoice's return URL (`…/callback/onepaycx.php?action=return&invoiceid=N`) re-checks the payment.
* The `mod_onepaycx_payments` table is recreated automatically if dropped, but it is not just a cache: it
  holds the recorded amount/currency per payment and the crediting lock. Without it, only the signed
  webhook credits invoices (with the API amount) and the buyer's return trip cannot poll.

## Uninstall

Deactivate the gateway in WHMCS, delete the three module paths listed above, and optionally drop the
`mod_onepaycx_payments` table and the `webhookSecret*` rows for gateway `onepaycx` in `tblpaymentgateways`.

## Development: compile & behaviour harness

`test/run.php` builds a throw-away fake WHMCS root whose `init.php` / `includes/*.php` define every WHMCS
function the module calls as recording stubs (`getGatewayVariables`, `checkCbInvoiceID`, `checkCbTransID`,
`logTransaction`, `addInvoicePayment`, `encrypt`/`decrypt`, `convertCurrency`, a JSON-backed
`WHMCS\Database\Capsule`), installs a canned HTTP transport (nothing leaves the machine), then lints every
file and drives each entry point at least once in isolated PHP processes: `onepaycx_MetaData`,
`onepaycx_config`, `onepaycx_config_validate`, `onepaycx_link` (create + reuse + API error), the signed
notify (valid with a surcharged API amount, duplicate, tampered, missing signature, rotated secret, other
event, partial payload, currency mismatch, concurrent and stale crediting locks, wrong method, inactive
module) and the buyer return (paid with a surcharged API amount, revisit without any API call, pending,
reference mismatch, expired, no local record, currency mismatch, Convert To, bad input).

```bash
php test/run.php      # exit 0 and "PASS" = good
```

Run it before shipping any change to the module.

## Changelog

### 1.0.0
* Initial release: invoice button, signed webhook, buyer-return polling, Convert To support.
* Invoices are credited with the recorded invoice amount, never the surcharged API amount; currency is
  cross-checked; crediting is locked against the webhook/return race; the buyer-return handler only ever
  polls payments this module created (no payment-list fallback); the pay button is a link so a preset
  payment method survives.
