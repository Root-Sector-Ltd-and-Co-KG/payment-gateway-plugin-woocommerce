=== WooCommerce Payment Gateway App ===
Contributors: r00tsector
Tags: payment gateway, woocommerce, credit card, direct debit, unified checkout
Requires at least: 6.1
Tested up to: 6.9.4
Requires PHP: 8.1
Stable tag: dev
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0

A powerful, unified payment gateway solution for WooCommerce. Integrate multiple payment providers like Stripe, GoCardless, and Coinbase Commerce through a single, seamless checkout experience.

== Description ==

The **WooCommerce Payment Gateway App** plugin lets you keep all provider
settings, routing and webhook handling in Payment Gateway App while showing
only a single gateway at checkout. Orders stay in sync via secure webhooks.

**Key Features**

- One clean checkout button for multiple processors
- Centralised management of providers inside Payment Gateway App
- Secure HMAC-SHA256 signed webhooks keep order statuses accurate
- API Key authentication for checkout session creation
- Debug mode with request IDs and safe gateway metadata for support
- Built for growth – add new providers without touching WordPress

== Requirements ==

- A running instance of Payment Gateway App
- WordPress with WooCommerce activated

== Installation ==

1. Download **`woocommerce-payment-gateway-app.zip`**.
2. In WordPress go to **Plugins → Add New → Upload Plugin**.
3. Select the ZIP, click **Install Now**, then **Activate Plugin**.

== Configuration ==

1. Go to **WooCommerce → Settings → Payments**.
2. Click **Manage** next to **Payment Gateway App**.
3. Fill in the fields:
   - **Enable/Disable** – turn the gateway on/off
   - **Title / Description** – what customers see at checkout
   - **API Domain** – domain of your backend (e.g. `api.payment-gateway.app`)
   - **Site ID** – from Payment Gateway App admin → Sites → Edit
   - **API Key** – create one under Payment Gateway App admin → API Keys with `checkout:create` scope
   - **Webhook Signing Secret** – the `whsec_`-prefixed secret from Payment Gateway App admin → Sites → Edit → Webhook Signing Secret
   - **Debug Log** – enable only while troubleshooting (see **Debug log security** below)
   - **Pass Billing / Shipping Address, Pass Items** – optional extra data

4. Click **Save changes**. You are ready to accept payments!

== Webhook (IPN) ==

The plugin registers a webhook endpoint automatically at:

`[YOUR-SITE]/wc-api/wc_payment_gateway_app`

When a transaction status changes, Payment Gateway App sends a POST
request to this URL. Each request includes two signature headers:

- `X-Signature-Timestamp` - Unix timestamp (seconds) of when the request was signed
- `X-Signature-HMAC-SHA256` - HMAC-SHA256 hex digest of `{timestamp}.{body}` using your Webhook Signing Secret

The plugin verifies both headers before processing. If verification
fails the request is rejected and logged (when Debug Log is enabled).

If you suspect your Webhook Signing Secret has been compromised,
regenerate it in Payment Gateway App admin -> Sites -> Edit and update
the value in the WooCommerce plugin settings.

== Disputes and chargebacks ==

Dispute webhooks may include `disputeStatus`, `chargebackStatus`, nested
`chargeback.*` fields, or a top-level string `status` such as `open`,
`under_review`, `won`, `lost`, or `accepted`. Numeric transaction statuses are
still handled as normal payment status updates when no dispute status is
present.

For active or merchant-loss dispute statuses (`open`, `under_review`, `lost`,
`accepted`), the plugin adds an order note with the gateway transaction ID,
dispute ID, request ID, and credit-note number when available, then marks the
WooCommerce order as `refunded`. Duplicate dispute webhook retries are tracked
in order meta so the admin timeline is not spammed with repeated notes.

`won` disputes are intentionally manual-only. The plugin records an order note
with the available request/dispute metadata and returns `OK`, but it does not
automatically complete, reopen, refund, or otherwise mutate the order. Process
won chargebacks manually in WooCommerce after reviewing the gateway evidence.

Checkout requests blocked by an unresolved dispute show a customer-safe error
message and include the gateway request ID when the API provides one. Use that
request ID to correlate the customer report with Payment Gateway App logs.

== Customer risk holds ==

Customer risk holds configured in Payment Gateway App are enforced when the
plugin creates a checkout session. A final block
(`CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD`) stops checkout and asks the customer to
contact support. A restricted hold
(`CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD`) stops the current attempt and asks the
customer to choose an allowed bank-transfer method. Existing successful
checkout responses continue to redirect to the hosted payment page.

Customer notices use fixed, translated text and may include only a sanitized
gateway request ID. Even with Debug Log enabled, the plugin records only
bounded diagnostic fields such as the error code, request ID, hold ID/action,
and allowed provider identifiers. It never logs the raw API response, customer
data, API credentials, or backend error message. With Debug Log disabled, these
failures do not create log entries.

== Debug log security ==

When **Debug Log** is enabled, failed checkout requests and webhook
verification issues may be written to WooCommerce logs
(**WooCommerce → Status → Logs**, source `woocommerce-payment-gateway-app`).
Those entries include safe gateway metadata such as gateway code, request ID,
transaction ID, external reference, dispute status, and customer-risk-hold
fields instead of full checkout request bodies or raw gateway response bodies.
Keep debug mode **disabled on production stores** unless you are actively
diagnosing an issue, and restrict log access to trusted administrators.

Checkout requests blocked by an unresolved dispute use
`CHECKOUT_BLOCKED_BY_DISPUTE` and show a customer-safe support message with the
gateway request ID when available. Final merchant-loss customer risk holds use
`CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD`; when safe methods are allowed,
`CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD` asks the customer to choose an available
bank-transfer option such as wire or Wise.

== Changelog ==

= 1.1.1 =

- Fix: Keep the release archive filename, packaged PHP version, and packaged README stable tag synchronized with the release tag.
- CI: Reject non-`x.y.z` release tags and releases without a matching changelog entry.
- Docs: Populate the GitHub release description from the matching changelog section.

= 1.1.0 =

- Enhancement: Enforce customer risk hold checkout blocks and safe provider restrictions with customer-safe notices.
- Enhancement: Display checkout API request IDs in customer-facing failure messages when available.
- Enhancement: Accept dispute-only webhooks with supported dispute status fields, including nested chargeback status and top-level string `status`.
- Enhancement: Add traceable dispute order notes and idempotent duplicate-note handling for webhook retries.
- Enhancement: Treat `won` disputes as manual-only trace events while marking non-won disputes as refunded.
- Security: Sanitize debug logging to avoid full checkout request bodies, raw webhook payloads, and full gateway response bodies.
- Docs: Document request ID, dispute, credit-note, and manual won-dispute behavior.

= 1.0.6 =

- Fix: Align checkout line-item types with the gateway API by using `goods` and `digital_service` identifiers.

= 1.0.5 =

- Enhancement: Prefer API `message` values for checkout failures while retaining the legacy `error` fallback.
- Enhancement: Update the default payment description to reference wire transfer options.
- Docs: Update WordPress compatibility and debug-log security guidance.

= 1.0.4 =

- Enhancement: Display and log customer-risk-hold checkout blocks and safe bank-transfer restrictions with request IDs.
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
