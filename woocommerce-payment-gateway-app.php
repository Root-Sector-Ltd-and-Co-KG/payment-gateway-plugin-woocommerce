<?php
/**
 * Plugin Name: WooCommerce Payment Gateway App
 * Plugin URI: https://payment-gateway.app
 * Description: Unified payment-gateway extension for WooCommerce — connect multiple providers through your self-hosted Payment Gateway App.
 * Version: dev
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Root Sector Ltd. & Co. KG
 * Author URI: https://root-sector.com
 * WC requires at least: 9.2
 * WC tested up to: 9.9
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */
defined('ABSPATH') || exit;

add_action('plugins_loaded', 'init_woocommerce_payment_gateway_app', 0);

function init_woocommerce_payment_gateway_app()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WooCommerce_Payment_Gateway_App extends WC_Payment_Gateway
    {
		// Properties
        protected $site_secret_key;
        protected $api_domain;
        protected $currency;
        protected $debug;
        protected $log;
        protected $site_id;
		
		// Constructor
        public function __construct()
        {
            $this->id = 'payment_gateway_app';
            $this->method_title = __('Payment Gateway App', 'woo-payment-gateway-app');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->site_secret_key = $this->get_option('site_secret_key');
            $this->site_id = $this->get_option('site_id');
            $this->api_domain = rtrim($this->get_option('api_domain'), '/') . '/';
            $this->currency = get_woocommerce_currency();
            $this->debug = 'yes' === $this->get_option('debug', 'no');

            if ($this->debug) {
                $this->log = wc_get_logger();
            }

            $this->init_hooks();
        }

		// Hooks
        private function init_hooks()
        {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_api_wc_payment_gateway_app', array($this, 'webhook_response'));
            add_filter('woocommerce_thankyou_order_received_text', array($this, 'custom_thankyou_text'), 10, 2);
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'add_status_check_to_thankyou'), 20);
        }

		// Admin Form
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woo-payment-gateway-app'),
                    'type' => 'checkbox',
                    'label' => __('Enable Payment Gateway App', 'woo-payment-gateway-app'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woo-payment-gateway-app'),
                    'type' => 'text',
                    'description' => __('Shown to customers at checkout.', 'woo-payment-gateway-app'),
                    'default' => __('Secure Checkout via payment-gateway.app', 'woo-payment-gateway-app')
                ),
                'description' => array(
                    'title' => __('Description', 'woo-payment-gateway-app'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woo-payment-gateway-app'),
                    'default' => __('Pay securely with credit/debit cards, crypto, bank transfer, or local options.', 'woo-payment-gateway-app')
                ),
                'api_domain' => array(
                    'title' => __('API Domain', 'woo-payment-gateway-app'),
                    'type' => 'text',
                    'description' => __('API Domain of your Payment Gateway App without protocol. Example: api.payment-gateway.app (instead of "https://api.payment-gateway.app").', 'woo-payment-gateway-app'),
                    'default' => ''
                ),
                'site_id' => array(
                    'title' => __('Site ID', 'woo-payment-gateway-app'),
                    'type' => 'text',
                    'description' => __('Copy the Site ID from Payment Gateway App Dashboard > Sites.', 'woo-payment-gateway-app'),
                    'default' => ''
                ),
                'site_secret_key' => array(
                    'title' => __('Site Secret Key', 'woo-payment-gateway-app'),
                    'type' => 'password',
                    'description' => __('Copy the site secret key from Payment Gateway App Dashboard > Sites.', 'woo-payment-gateway-app'),
                    'default' => ''
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'woo-payment-gateway-app'),
                    'type' => 'checkbox',
                    'description' => __('Write requests and responses to WooCommerce logs.', 'woo-payment-gateway-app'),
                    'default' => 'no'
                ),
                'pass_billing_address' => array(
                    'title' => __('Pass Billing Address', 'woo-payment-gateway-app'),
                    'type' => 'checkbox',
                    'label' => __('Enable passing billing address', 'woo-payment-gateway-app'),
                    'description' => __('Send the customer’s billing address to the payment gateway app.', 'woo-payment-gateway-app'),
                    'default' => 'yes'
                ),
                'pass_shipping_address' => array(
                    'title' => __('Pass shipping address', 'woo-payment-gateway-app'),
                    'type' => 'checkbox',
                    'label' => __('Enable passing shipping address', 'woo-payment-gateway-app'),
                    'description' => __('Send the customer’s shipping address to the payment gateway app.', 'woo-payment-gateway-app'),
                    'default' => 'yes'
                ),
                'pass_items' => array(
                    'title' => __('Pass Items', 'woo-payment-gateway-app'),
                    'type' => 'checkbox',
                    'label' => __('Enable passing items', 'woo-payment-gateway-app'),
                    'description' => __('Send invoice line-items to the payment gateway app.', 'woo-payment-gateway-app'),
                    'default' => 'yes'
                ),
            );
        }

		// Payment Flow
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Sanitize and validate inputs
            $amount = round($order->get_total() * 100); // Total amount including items, shipping, and tax
            $email = sanitize_email($order->get_billing_email());
            $cancelurl = esc_url($order->get_cancel_order_url());
            $returnurl = esc_url($this->get_return_url($order));
            $ipnurl = esc_url(add_query_arg('wc-api', 'wc_payment_gateway_app', home_url('/', 'https')));

            if ($this->debug) {
                $this->log->debug('Creating Payment Session', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'amount' => $amount,
                    'email' => $email,
                    'order_id' => $order_id,
                    'return_url' => $returnurl,
                    'cancel_url' => $cancelurl,
                    'ipn_url' => $ipnurl
                ));
            }

            $payment_session_url = 'https://' . $this->api_domain . 'api/v1/sessions/create';

            if ($this->debug) {
                $this->log->debug('Preparing hashData', array('source' => 'woocommerce-payment-gateway-app', 'order_id' => $order_id));
            }

            $hashData = array(
                'amount' => round($order->get_total() * 100), // Amount in cents
                'currency' => get_woocommerce_currency(),
                'email' => $email,
                'customInvoiceId' => (string) $order_id,
                'returnUrl' => $returnurl,
                'cancelUrl' => $cancelurl,
                'ipnUrl' => $ipnurl,
            );

            if ('yes' === $this->get_option('pass_billing_address')) {
                $hashData['billingAddress'] = array(
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'address1' => $order->get_billing_address_1(),
                    'address2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'phone' => $order->get_billing_phone(),
                );
            }
        
            if ('yes' === $this->get_option('pass_shipping_address')) {
                $hashData['shippingAddress'] = array(
                    'firstName' => $order->get_shipping_first_name(),
                    'lastName' => $order->get_shipping_last_name(),
                    'address1' => $order->get_shipping_address_1(),
                    'address2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country(),
                );
            }
        
            if ('yes' === $this->get_option('pass_items')) {
                $items = array();

                // Product items
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    $item_type = ($product && $product->is_virtual()) ? 'virtual' : 'physical';
                    $items[] = array(
                        'name'     => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'amount'   => round(($item->get_subtotal() / $item->get_quantity()) * 100),
                        'type'     => $item_type
                    );
                }
    
                // Shipping item
                if ($order->get_shipping_total() > 0) {
                    $items[] = array(
                        'name'     => 'Shipping',
                        'quantity' => 1,
                        'amount'   => round($order->get_shipping_total() * 100),
                        'type'     => 'shipping'
                    );
                }
    
                // Tax item
                if ($order->get_total_tax() > 0) {
                    $items[] = array(
                        'name'     => 'Tax',
                        'quantity' => 1,
                        'amount'   => round($order->get_total_tax() * 100),
                        'type'     => 'tax'
                    );
                }
    
                // Correct for rounding errors by ensuring the sum of items exactly equals the order total.
                $items_total_cents = 0;
                foreach ($items as $item) {
                    $items_total_cents += $item['amount'] * $item['quantity'];
                }
    
                $total_cents = round($order->get_total() * 100);
                $diff_cents = $total_cents - $items_total_cents;
    
                if ($diff_cents != 0) {
                    // Find the tax item and adjust it. If no tax item, adjust the last item.
                    $adjusted = false;
                    for ($i = 0; $i < count($items); $i++) {
                        if ($items[$i]['type'] === 'tax') {
                            $items[$i]['amount'] += $diff_cents;
                            $adjusted = true;
                            break;
                        }
                    }
    
                    // If no tax item was found, adjust the last item in the array.
                    if (!$adjusted && count($items) > 0) {
                        $items[count($items) - 1]['amount'] += $diff_cents;
                    }
                }
    
                $hashData['items'] = $items;
            }
            
            if ($this->debug) {
                $this->log->debug('Preparing to send request', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'url' => $payment_session_url,
                    'parameters' => $hashData
                ));
            }

            $response = wp_remote_post(
                $payment_session_url,
                array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Site-Id' => $this->site_id,
                        'Site-Secret-Key' => $this->site_secret_key,
                    ),
                    'body' => json_encode($hashData),
                )
            );

            if ($this->debug) {
                $this->log->debug('Received response', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'response' => $response
                ));
            }

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                if ($this->debug) {
                    $this->log->error('Request Error', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'error_message' => $error_message
                    ));
                }
                wc_add_notice(__('Payment session creation failed. Error: ', 'woo-payment-gateway-app') . $error_message, 'error');
                return array(
                    'result' => 'failure',
                    'redirect' => $cancelurl
                );
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);

            if ($response_code !== 200) {
                $error_message = $response_body['error'] ?? __('Payment session creation failed due to an unexpected error.', 'woo-payment-gateway-app');

                // Check for the specific rounding error and provide a more user-friendly message.
                if (strpos($error_message, 'sum of item amounts') !== false && strpos($error_message, 'does not match total amount') !== false) {
                    $error_message = __('The total amount of the items does not match the order total. This can be caused by a rounding difference. Please contact support for assistance.', 'woo-payment-gateway-app');
                }

                // Append the request ID for debugging purposes if it exists.
                if (isset($response_body['request_id'])) {
                    $error_message .= ' (Request-ID: ' . esc_html($response_body['request_id']) . ')';
                }

                wc_add_notice(__('Payment session creation failed. Error: ', 'woo-payment-gateway-app') . $error_message, 'error');

                if ($this->debug) {
                    $this->log->error('Unexpected Response Code', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'response_code' => $response_code,
                        'response_body' => $response_body
                    ));
                }

                $order->update_status('cancelled', __('Order cancelled due to payment gateway error. Reason: ', 'woo-payment-gateway-app') . $error_message);

                return array(
                    'result' => 'failure',
                    'redirect' => $cancelurl
                );
            }

            if ($this->debug) {
                $this->log->debug('Decoded response body', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'response_body' => $response_body
                ));
            }

            if (isset($response_body['paymentUrl'])) {
                return array(
                    'result' => 'success',
                    'redirect' => $response_body['paymentUrl']
                );
            }

            if ($this->debug) {
                $this->log->error('Invalid Response', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'response_body' => $response_body
                ));
            }

            $error_message = $response_body['error'] ?? __('an unexpected error occurred', 'woo-payment-gateway-app');
            wc_add_notice(__('Payment session creation failed. Reason: ', 'woo-payment-gateway-app') . $error_message, 'error');            return array(
                'result' => 'failure',
                'redirect' => $cancelurl
            );
        }

        public function receipt_page($order_id)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay.', 'woo-payment-gateway-app') . '</p>';
            echo '<a class="button alt" href="' . esc_url($this->get_return_url(wc_get_order($order_id))) . '">' . __('Pay Now', 'woo-payment-gateway-app') . '</a>';
        }

        public function webhook_response()
        {
            if ($this->debug) {
                $this->log->debug('Incoming webhook request', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'headers' => getallheaders(),
                    'raw_body' => file_get_contents('php://input'),
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'query_params' => $_GET
                ));
            }

            $appSecret = $this->site_secret_key;

            // --- New Webhook Verification Logic ---
            $received_timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null;
            $received_signature = $_SERVER['HTTP_X_SIGNATURE_HMAC_SHA256'] ?? null;
            $raw_body = file_get_contents('php://input');

            if (!$received_timestamp || !$received_signature) {
                if ($this->debug) {
                    $this->log->error('Webhook signature headers missing', array('source' => 'woocommerce-payment-gateway-app'));
                }
                status_header(400);
                exit("Signature headers missing");
            }

            // Check if timestamp is recent (e.g., within 5 minutes) to prevent replay attacks.
            if (time() - (int)$received_timestamp > 300) {
                if ($this->debug) {
                    $this->log->error('Webhook timestamp is too old', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'received_timestamp' => $received_timestamp
                    ));
                }
                status_header(400);
                exit("Webhook timestamp too old");
            }

            // Recreate the signature string.
            $string_to_sign = $received_timestamp . '.' . $raw_body;
            $computed_hash = hash_hmac('sha256', $string_to_sign, $appSecret);

            // Securely compare the signatures.
            if (!hash_equals($computed_hash, $received_signature)) {
                if ($this->debug) {
                    $this->log->error('Invalid webhook signature', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'string_to_hash' => $string_to_sign,
                        'computed_hash' => $computed_hash,
                        'received_signature' => $received_signature
                    ));
                }
                status_header(400);
                exit("Invalid signature");
            }
            // --- End New Verification Logic ---

            $parsed_request = json_decode($raw_body, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed_request)) {
                if ($this->debug) {
                    $this->log->error('Invalid JSON in webhook request', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'raw_body' => $raw_body,
                        'json_error' => json_last_error_msg()
                    ));
                }
                status_header(400);
                exit("Invalid JSON");
            }

            $order = wc_get_order($parsed_request['customInvoiceId']);
            if (!$order) {
                status_header(404);
                exit("Order not found");
            }

            switch ($parsed_request['status']) {
                case 0:
                    $order->update_status('on-hold', sprintf(__('Payment pending. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
                case 1:
                    $order->update_status('completed', sprintf(__('Payment completed. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
                case 2:
                    $order->update_status('failed', sprintf(__('Payment failed. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
                case 3:
                    $order->update_status('refunded', sprintf(__('Payment refunded. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
                case 4:
                    $order->update_status('refunded', sprintf(__('Chargeback received. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
                case -1:
                    $order->update_status('on-hold', sprintf(__('Payment initiated. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
                case -2:
                    $order->update_status('cancelled', sprintf(__('Payment cancelled. Transaction ID: %s', 'woo-payment-gateway-app'), $parsed_request['id']));
                    break;
            }

            status_header(200);
            exit("OK");
        }

        /**
         * Customize the thank you page message.
         */
        public function custom_thankyou_text($text, $order)
        {
            if ($order && $order->get_payment_method() === $this->id) {
                $email = $order->get_billing_email();
                $text = sprintf(
                    __('Thank you for your order! We are processing your payment and will send a confirmation email to %s shortly. You can check the status of your order using the button below.', 'woo-payment-gateway-app'),
                    '<strong>' . esc_html($email) . '</strong>'
                );
            }
            return $text;
        }

        /**
         * Add a refresh/status check button to the thank you page.
         */
        public function add_status_check_to_thankyou($order_id)
        {
            $order = wc_get_order($order_id);
            if ($order) {
                $view_order_url = $order->get_view_order_url();
                echo '<div style="margin: 2em 0;">';
                echo '<p>' . __('Your payment is being confirmed by the gateway. This can take a few moments. Use the button below to see the latest status. If you purchased a digital product, the download link will appear on the order details page once payment is complete.', 'woo-payment-gateway-app') . '</p>';
                echo '<a href="' . esc_url($view_order_url) . '" class="button">' . __('View Order Details & Check Status', 'woo-payment-gateway-app') . '</a>';
                echo '</div>';
            }
        }
    }

	// Register gateway with WooCommerce
    function add_payment_gateway_app($methods)
    {
        $methods[] = 'WooCommerce_payment_gateway_app';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_payment_gateway_app');
}

// Declare compatibility with HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});