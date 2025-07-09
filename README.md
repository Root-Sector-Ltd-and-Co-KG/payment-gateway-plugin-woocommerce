=== WooCommerce Multi Payment Gateway ===
Contributors: r00tsector
Tags: payment gateway, woocommerce, credit card, direct debit, stripe, gocardless, coinbase commerce
Requires at least: 6.1
Tested up to: 6.7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0

A powerful, unified payment gateway solution for WooCommerce. Integrate multiple payment providers like Stripe, GoCardless, and Coinbase Commerce through a single, seamless checkout experience.

== Description ==

The **WooCommerce Multi Payment Gateway** plugin connects your WooCommerce store to your self-hosted Multi Payment Gateway instance. This allows you to offer a variety of payment methods to your customers without cluttering your checkout page with multiple separate gateways.

By centralizing your payment provider configurations in the Multi Payment Gateway, you can easily manage, switch, and add new providers without changing your WooCommerce setup. This plugin securely creates payment sessions and handles webhook notifications to keep your order statuses up-to-date.

**Key Features:**

- **Unified Checkout:** Consolidate multiple payment providers into a single gateway option.
- **Centralized Management:** Manage all your payment providers from the Multi Payment Gateway admin panel.
- **Secure:** Customer data is handled securely, and secret keys are stored properly.
- **Extensible:** Easily add support for new payment providers as your business grows.
- **Developer Friendly:** Includes a debug mode for easy troubleshooting.

== Requirements ==

- A running instance of the Multi Payment Gateway.
- A WordPress installation with the WooCommerce plugin activated.

== Installation ==

1.  Download the plugin `.zip` file from the repository.
2.  In your WordPress Dashboard, navigate to **Plugins > Add New**.
3.  Click the **Upload Plugin** button at the top of the page.
4.  Browse for the `woocommerce-multi-payment-gateway.zip` file on your computer.
5.  Click **Install Now** and then **Activate Plugin**.

== Configuration ==

After installation, configure the plugin by following these steps:

1.  Navigate to **WooCommerce > Settings > Payments**.
2.  Find **Multi Payment Gateway** in the list and click the **Manage** button.
3.  Configure the following fields:

    - **Enable/Disable**: Check this box to enable the Multi Payment Gateway at checkout.
    - **Title**: The payment method title shown to customers during checkout (e.g., "Pay with Card or Bank Account").
    - **Description**: The description shown below the title, providing more details to the customer.
    - **Default Main Backend Domain**: Enter the domain of your Multi Payment Gateway main backend instance.
      - **Important**: Do not include `https://` or a trailing slash.
      - _Example_: `api.your-gateway.com`
    - **Site ID**: Enter the **Site ID** from your site's edit page in the Multi Payment Gateway admin panel.
    - **Site Secret Key**: Enter the **Secret Key** for the site you configured in your Multi Payment Gateway admin panel. This is used to securely authenticate requests. You can find this key in your Site's settings page within the Multi Payment Gateway admin.
    - **Debug Log**: Enable this to log all requests and responses to the WooCommerce system logs (`WooCommerce > Status > Logs`). This is very useful for troubleshooting integration issues.
    - **Pass Billing Address**: When enabled, the customer's billing address is sent to the payment gateway. This is recommended for fraud prevention and address verification (AVS).
    - **Pass Shipping Address**: When enabled, the customer's shipping address is sent.
    - **Pass Items**: When enabled, details of the individual items in the cart are sent to the payment gateway. Some providers may require this for level 2/3 data processing.

4.  Click **Save changes**.

Your WooCommerce store is now ready to process payments through the Multi Payment Gateway!

== Changelog ==

= 1.0.2 =

- Fix: Correctly determine if a product is physical or virtual to prevent item type validation errors.
- Enhancement: Improved descriptions for plugin settings for better clarity.
- Enhancement: Updated README with detailed installation and configuration instructions.

= 1.0.1 =

- Fix: Get order total from WooCommerce order object and convert to cents

= 1.0.0 =

- Released: Initial version of the software.
