=== WooCommerce Payment Gateway App ===
Contributors: r00tsector
Tags: payment gateway, woocommerce, credit card, direct debit, unified checkout
Requires at least: 6.1
Tested up to: 6.7.1
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0

A powerful, unified payment gateway solution for WooCommerce. Integrate multiple payment providers like Stripe, GoCardless, and Coinbase Commerce through a single, seamless checkout experience.

== Description ==

The **WooCommerce Payment Gateway App** plugin lets you keep all provider
settings, routing and webhook handling in Payment Gateway App while showing
only a single gateway at checkout. Orders stay in sync via secure webhooks.

**Key Features**

* One clean checkout button for multiple processors
* Centralised management of providers inside Payment Gateway App
* Secure HMAC-SHA256 signed webhooks keep order statuses accurate
* API Key authentication for checkout session creation
* Debug mode and detailed logs for developers
* Built for growth – add new providers without touching WordPress

== Requirements ==

* A running instance of Payment Gateway App
* WordPress with WooCommerce activated

== Installation ==

1. Download **`woocommerce-payment-gateway-app.zip`**.
2. In WordPress go to **Plugins → Add New → Upload Plugin**.
3. Select the ZIP, click **Install Now**, then **Activate Plugin**.

== Configuration ==

1. Go to **WooCommerce → Settings → Payments**.
2. Click **Manage** next to **Payment Gateway App**.
3. Fill in the fields:

   * **Enable/Disable** – turn the gateway on/off
   * **Title / Description** – what customers see at checkout
   * **API Domain** – domain of your backend (e.g. `api.payment-gateway.app`)
   * **Site ID** – from Payment Gateway App admin → Sites → Edit
   * **API Key** – create one under Payment Gateway App admin → API Keys with `checkout:write` scope
   * **Webhook Signing Secret** – the `whsec_`-prefixed secret from Payment Gateway App admin → Sites → Edit → Webhook Signing Secret
   * **Debug Log** – enable for verbose Woo logs (`WooCommerce → Status → Logs`)
   * **Pass Billing / Shipping Address, Pass Items** – optional extra data

4. Click **Save changes**. You are ready to accept payments!

== Webhook (IPN) ==

The plugin registers a webhook endpoint automatically at:

`[YOUR-SITE]/wc-api/wc_payment_gateway_app`

When a transaction status changes, Payment Gateway App sends a POST
request to this URL. Each request includes two signature headers:

* `X-Signature-Timestamp` – Unix timestamp (seconds) of when the request was signed
* `X-Signature-HMAC-SHA256` – HMAC-SHA256 hex digest of `{timestamp}.{body}` using your Webhook Signing Secret

The plugin verifies both headers before processing. If verification
fails the request is rejected and logged (when Debug Log is enabled).

If you suspect your Webhook Signing Secret has been compromised,
regenerate it in Payment Gateway App admin → Sites → Edit and update
the value in the WooCommerce plugin settings.

== Changelog ==

= 1.0.4 =
- Security: Replaced Site Secret Key with dedicated Webhook Signing Secret (`whsec_` prefix) for IPN verification.
- Security: Added separate API Key field for checkout session authentication.
- Enhancement: Improved webhook verification with HMAC-SHA256 + timestamp replay protection.
- Docs: Updated README with Webhook/IPN section and new configuration fields.

= 1.0.3 =
- Docs: Updated README.

= 1.0.2 =

- Fix: Correctly determine if a product is physical or virtual to prevent item type validation errors.
- Enhancement: Improved descriptions for plugin settings for better clarity.
- Enhancement: Updated README with detailed installation and configuration instructions.

= 1.0.1 =

- Fix: Get order total from WooCommerce order object and convert to cents

= 1.0.0 =

- Released: Initial version of the software.
