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
        protected $api_key;
        protected $webhook_secret;
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
            $this->api_key = $this->get_option('api_key');
            $this->webhook_secret = $this->get_option('webhook_secret');
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

        /**
         * Prefer API `message`, then legacy `error` field.
         *
         * @param array<string, mixed>|null $response_body
         */
        private function get_api_error_message($response_body, $fallback)
        {
            if (!is_array($response_body)) {
                return $fallback;
            }
            if (!empty($response_body['message']) && is_string($response_body['message'])) {
                return $response_body['message'];
            }
            if (!empty($response_body['error']) && is_string($response_body['error'])) {
                return $response_body['error'];
            }
            return $fallback;
        }

        private function get_api_scalar($response_body, $keys)
        {
            if (!is_array($response_body)) {
                return '';
            }
            foreach ($keys as $key) {
                if (!empty($response_body[$key]) && is_scalar($response_body[$key])) {
                    return sanitize_text_field((string)$response_body[$key]);
                }
            }
            return '';
        }

        private function get_api_request_id($response_body)
        {
            return $this->get_api_scalar($response_body, array('requestId', 'requestID'));
        }

        private function format_customer_api_error($response_body, $fallback)
        {
            $message = trim($this->get_api_error_message($response_body, $fallback));
            $code = $this->get_api_scalar($response_body, array('code'));

            if ($code === 'CHECKOUT_BLOCKED_BY_DISPUTE') {
                $message = __('Payment cannot be started because an unresolved dispute is being reviewed. Please contact support.', 'woo-payment-gateway-app');
            } elseif ($code === 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD') {
                $message = __('Payment cannot be started because this customer account is under merchant review. Please contact support.', 'woo-payment-gateway-app');
            } elseif ($code === 'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD') {
                $message = __('Only bank transfer payment methods are available for this account. Please choose an available bank transfer option or contact support.', 'woo-payment-gateway-app');
            }

            $request_id = $this->get_api_request_id($response_body);
            if ($request_id !== '') {
                $message .= ' (Request-ID: ' . esc_html($request_id) . ')';
            }
            return $message;
        }

        private function get_safe_gateway_context($response_body, $extra = array())
        {
            $context = $extra;
            if (!is_array($response_body)) {
                return $context;
            }

            $fields = array(
                'gateway_code' => $this->get_api_scalar($response_body, array('code')),
                'request_id' => $this->get_api_request_id($response_body),
                'transaction_id' => $this->get_api_scalar($response_body, array('transactionId')),
                'external_reference' => $this->get_api_scalar($response_body, array('externalReference')),
                'dispute_id' => $this->get_api_scalar($response_body, array('disputeId')),
                'dispute_status' => $this->get_api_scalar($response_body, array('disputeStatus', 'chargebackStatus')),
                'customer_risk_hold_id' => $this->get_api_scalar($response_body, array('customerRiskHoldId')),
                'customer_risk_action' => $this->get_api_scalar($response_body, array('customerRiskAction')),
                'customer_risk_reason' => $this->get_api_scalar($response_body, array('customerRiskReason')),
                'amount' => $this->get_api_scalar($response_body, array('amount')),
                'currency' => $this->get_api_scalar($response_body, array('currency')),
            );
            foreach ($fields as $key => $value) {
                if ($value !== '') {
                    $context[$key] = $value;
                }
            }
            return $context;
        }

        private function get_safe_checkout_request_context($payload, $extra = array())
        {
            $context = $extra;
            if (!is_array($payload)) {
                return $context;
            }

            foreach (array('amount', 'currency', 'externalReference') as $key) {
                if (isset($payload[$key]) && is_scalar($payload[$key])) {
                    $context[$key] = sanitize_text_field((string)$payload[$key]);
                }
            }
            if (isset($payload['items']) && is_array($payload['items'])) {
                $context['item_count'] = count($payload['items']);
            }
            $context['billing_address_passed'] = isset($payload['billingAddress']);
            $context['shipping_address_passed'] = isset($payload['shippingAddress']);
            foreach (array('returnUrl', 'cancelUrl', 'ipnUrl') as $url_key) {
                if (!empty($payload[$url_key]) && is_string($payload[$url_key])) {
                    $host = wp_parse_url($payload[$url_key], PHP_URL_HOST);
                    if (is_string($host) && $host !== '') {
                        $context[$url_key . '_host'] = sanitize_text_field($host);
                    }
                }
            }
            return $context;
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
                    'default' => __('Pay securely with credit/debit cards, crypto, wire transfer, or local options.', 'woo-payment-gateway-app')
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
                'api_key' => array(
                    'title' => __('API Key', 'woo-payment-gateway-app'),
                    'type' => 'password',
                    'description' => __('Create an API Key with checkout:create scope from Payment Gateway App Dashboard > API Keys.', 'woo-payment-gateway-app'),
                    'default' => ''
                ),
                'webhook_secret' => array(
                    'title' => __('Webhook Signing Secret', 'woo-payment-gateway-app'),
                    'type' => 'password',
                    'description' => __('Copy the Webhook Signing Secret from Payment Gateway App Dashboard > Sites > Edit Site. This secret verifies that IPN/webhook notifications are genuinely from your payment gateway and have not been tampered with (HMAC-SHA256). Starts with whsec_.', 'woo-payment-gateway-app'),
                    'default' => ''
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'woo-payment-gateway-app'),
                    'type' => 'checkbox',
                    'description' => __('Write support-safe request and response metadata to WooCommerce logs.', 'woo-payment-gateway-app'),
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
                'tax_handling' => array(
                    'title' => __('Item Tax Handling', 'woo-payment-gateway-app'),
                    'type' => 'select',
                    'description' => __('Controls how tax is included in line-item prices sent to the payment gateway.<br><strong>Included (recommended):</strong> Tax is included in each item\'s unit price. No separate tax line. Best when Payment Gateway App calculates tax.<br><strong>Separate line item:</strong> Items are sent at net prices with a separate "Tax" line item. Use only when Payment Gateway App does NOT calculate tax.', 'woo-payment-gateway-app'),
                    'default' => 'included',
                    'options' => array(
                        'included' => __('Tax included in item prices (recommended)', 'woo-payment-gateway-app'),
                        'separate' => __('Tax as separate line item', 'woo-payment-gateway-app'),
                    ),
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

            // Sanitize and validate inputs (use esc_url_raw for API payloads, not esc_url which is for HTML output)
            $email = sanitize_email($order->get_billing_email());
            $cancelurl = esc_url_raw($order->get_cancel_order_url());
            $returnurl = esc_url_raw($this->get_return_url($order));
            $ipnurl = esc_url_raw(add_query_arg('wc-api', 'wc_payment_gateway_app', home_url('/', 'https')));

            if ($this->debug) {
                $this->log->debug('Creating Payment Session', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'order_id' => $order_id,
                    'amount' => round($order->get_total() * 100),
                    'currency' => get_woocommerce_currency()
                ));
            }

            $payment_session_url = 'https://' . $this->api_domain . 'v1/checkouts/' . $this->site_id . '/create';

            if ($this->debug) {
                $this->log->debug('Preparing hashData', array('source' => 'woocommerce-payment-gateway-app', 'order_id' => $order_id));
            }

            $hashData = array(
                'amount' => round($order->get_total() * 100), // Amount in cents
                'currency' => get_woocommerce_currency(),
                'email' => $email,
                'externalReference' => (string) $order_id,
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
                $tax_handling = $this->get_option('tax_handling', 'included');

                if ($tax_handling === 'included') {
                    // Tax-included mode: each item's unitPrice contains its share of tax.
                    // No separate tax line item. This is the recommended mode when Payment
                    // Gateway App has its own tax calculation enabled (it extracts tax from gross).

                    // Product items (gross = net + tax, after coupons)
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        $item_type = ($product && $product->is_virtual()) ? 'digital_service' : 'goods';
                        $quantity = max(1, (int)$item->get_quantity());
                        $line_gross = $item->get_total() + $item->get_total_tax();
                        $items[] = array(
                            'description' => $item->get_name(),
                            'quantity'    => $quantity,
                            'unitPrice'   => round(($line_gross / $quantity) * 100),
                            'itemType'    => $item_type
                        );
                    }

                    // Shipping item (gross)
                    $shipping_gross = $order->get_shipping_total() + $order->get_shipping_tax();
                    if ($shipping_gross > 0) {
                        $items[] = array(
                            'description' => 'Shipping',
                            'quantity'    => 1,
                            'unitPrice'   => round($shipping_gross * 100),
                            'itemType'    => 'shipping'
                        );
                    }

                    // Fee items (gross) — e.g. surcharges added by other plugins
                    foreach ($order->get_fees() as $fee) {
                        $fee_gross = $fee->get_total() + $fee->get_total_tax();
                        if ($fee_gross != 0) {
                            $items[] = array(
                                'description' => $fee->get_name(),
                                'quantity'    => 1,
                                'unitPrice'   => round($fee_gross * 100),
                                'itemType'    => $fee_gross < 0 ? 'discount' : 'digital_service'
                            );
                        }
                    }
                } else {
                    // Separate-tax mode: items are sent at net prices (after coupons, before tax)
                    // with a single "Tax" line item. Use this when Payment Gateway App does NOT
                    // calculate tax and you want to pass WooCommerce's tax calculation through.

                    // Product items (net, after coupons)
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        $item_type = ($product && $product->is_virtual()) ? 'digital_service' : 'goods';
                        $quantity = max(1, (int)$item->get_quantity());
                        $items[] = array(
                            'description' => $item->get_name(),
                            'quantity'    => $quantity,
                            'unitPrice'   => round(($item->get_total() / $quantity) * 100),
                            'itemType'    => $item_type
                        );
                    }

                    // Shipping item (net)
                    if ($order->get_shipping_total() > 0) {
                        $items[] = array(
                            'description' => 'Shipping',
                            'quantity'    => 1,
                            'unitPrice'   => round($order->get_shipping_total() * 100),
                            'itemType'    => 'shipping'
                        );
                    }

                    // Fee items (net)
                    foreach ($order->get_fees() as $fee) {
                        if ($fee->get_total() != 0) {
                            $items[] = array(
                                'description' => $fee->get_name(),
                                'quantity'    => 1,
                                'unitPrice'   => round($fee->get_total() * 100),
                                'itemType'    => $fee->get_total() < 0 ? 'discount' : 'digital_service'
                            );
                        }
                    }

                    // Tax line item
                    if ($order->get_total_tax() > 0) {
                        $items[] = array(
                            'description' => 'Tax',
                            'quantity'    => 1,
                            'unitPrice'   => round($order->get_total_tax() * 100),
                            'itemType'    => 'tax'
                        );
                    }
                }

                // Correct for rounding errors by ensuring the sum of items exactly equals the order total.
                $items_total_cents = 0;
                foreach ($items as $it) {
                    $items_total_cents += $it['unitPrice'] * $it['quantity'];
                }

                $total_cents = round($order->get_total() * 100);
                $diff_cents = $total_cents - $items_total_cents;

                if ($diff_cents != 0 && count($items) > 0) {
                    // Adjust the last product/shipping item (avoid adjusting discount items).
                    $adjusted = false;
                    for ($i = count($items) - 1; $i >= 0; $i--) {
                        if (in_array($items[$i]['itemType'], array('goods', 'digital_service', 'shipping', 'tax'), true)) {
                            $items[$i]['unitPrice'] += $diff_cents;
                            $adjusted = true;
                            break;
                        }
                    }
                    if (!$adjusted) {
                        $items[count($items) - 1]['unitPrice'] += $diff_cents;
                    }
                }

                $hashData['items'] = $items;
            }
            
            if ($this->debug) {
                $this->log->debug('Preparing to send request', $this->get_safe_checkout_request_context($hashData, array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'url' => $payment_session_url,
                )));
            }

            $response = wp_remote_post(
                $payment_session_url,
                array(
                    'timeout' => 30,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->api_key,
                    ),
                    'body' => json_encode($hashData),
                )
            );

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

            if ($this->debug) {
                $this->log->debug('Received response', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'response_code' => $response_code
                ));
            }

            if ($response_code !== 200) {
                $error_message = $this->format_customer_api_error(
                    $response_body,
                    __('Payment session creation failed due to an unexpected error.', 'woo-payment-gateway-app')
                );

                // Check for the specific rounding error and provide a more user-friendly message.
                if (strpos($error_message, 'sum of item amounts') !== false && strpos($error_message, 'does not match total amount') !== false) {
                    $error_message = __('The total amount of the items does not match the order total. This can be caused by a rounding difference. Please contact support for assistance.', 'woo-payment-gateway-app');
                }

                wc_add_notice(__('Payment session creation failed. Error: ', 'woo-payment-gateway-app') . $error_message, 'error');

                if ($this->debug) {
                    $this->log->error('Unexpected Response Code', $this->get_safe_gateway_context($response_body, array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'response_code' => $response_code,
                    )));
                }

                $order->update_status('cancelled', __('Order cancelled due to payment gateway error. Reason: ', 'woo-payment-gateway-app') . $error_message);

                return array(
                    'result' => 'failure',
                    'redirect' => $cancelurl
                );
            }

            if ($this->debug) {
                $this->log->debug('Decoded response body', $this->get_safe_gateway_context($response_body, array(
                    'source' => 'woocommerce-payment-gateway-app',
                )));
            }

            if (isset($response_body['paymentUrl'])) {
                return array(
                    'result' => 'success',
                    'redirect' => $response_body['paymentUrl']
                );
            }

            if ($this->debug) {
                $this->log->error('Invalid Response', $this->get_safe_gateway_context($response_body, array(
                    'source' => 'woocommerce-payment-gateway-app',
                )));
            }

            $error_message = $this->format_customer_api_error(
                $response_body,
                __('an unexpected error occurred', 'woo-payment-gateway-app')
            );
            wc_add_notice(__('Payment session creation failed. Reason: ', 'woo-payment-gateway-app') . $error_message, 'error');
            return array(
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
            $raw_body = file_get_contents('php://input');

            if ($this->debug) {
                $this->log->debug('Incoming webhook request', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                ));
            }

            // --- Webhook Signature Verification (HMAC-SHA256 signed with API Key) ---
            $received_timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null;
            $received_signature = $_SERVER['HTTP_X_SIGNATURE_HMAC_SHA256'] ?? null;

            if (!$received_timestamp || !$received_signature) {
                if ($this->debug) {
                    $this->log->error('Webhook signature headers missing', array('source' => 'woocommerce-payment-gateway-app'));
                }
                status_header(400);
                exit("Signature headers missing");
            }

            // Check if timestamp is recent (within 5 minutes) to prevent replay attacks.
            if (!is_numeric($received_timestamp)) {
                if ($this->debug) {
                    $this->log->error('Invalid webhook timestamp', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'received_timestamp' => $received_timestamp
                    ));
                }
                status_header(400);
                exit("Invalid signature timestamp");
            }

            if (abs(time() - (int)$received_timestamp) > 300) {
                if ($this->debug) {
                    $this->log->error('Webhook timestamp is too old', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'received_timestamp' => $received_timestamp
                    ));
                }
                status_header(400);
                exit("Webhook timestamp too old");
            }

            // Recreate the signature string and verify using the webhook signing secret.
            $string_to_sign = $received_timestamp . '.' . $raw_body;
            $computed_hash = hash_hmac('sha256', $string_to_sign, $this->webhook_secret);

            // Securely compare the signatures (timing-safe).
            if (!hash_equals($computed_hash, $received_signature)) {
                if ($this->debug) {
                    $this->log->error('Invalid webhook signature', array('source' => 'woocommerce-payment-gateway-app'));
                }
                status_header(400);
                exit("Invalid signature");
            }
            // --- End Webhook Signature Verification ---

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

            if (!isset($parsed_request['externalReference'], $parsed_request['status'], $parsed_request['id']) || !is_numeric($parsed_request['status'])) {
                if ($this->debug) {
                    $this->log->error('Missing or invalid webhook fields', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'parsed_request' => $parsed_request
                    ));
                }
                status_header(400);
                exit("Missing required fields");
            }

            $order = wc_get_order($parsed_request['externalReference']);
            if (!$order) {
                status_header(404);
                exit("Order not found");
            }

            $transaction_id = sanitize_text_field($parsed_request['id']);

            switch ((int) $parsed_request['status']) {
                case 0: // Pending
                    $order->update_status('on-hold', sprintf(__('Payment pending. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    break;
                case 1: // Completed
                    if (!$order->is_paid()) {
                        $order->payment_complete($transaction_id);
                        $order->add_order_note(sprintf(__('Payment completed. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    }
                    break;
                case 2: // Failed
                    $order->update_status('failed', sprintf(__('Payment failed. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    break;
                case 3: // Refunded
                    $order->update_status('refunded', sprintf(__('Payment refunded. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    break;
                case 4: // Chargeback/Disputed
                    $order->update_status('refunded', sprintf(__('Chargeback received. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    break;
                case -1: // Initiated
                    $order->update_status('on-hold', sprintf(__('Payment initiated. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    break;
                case -2: // Cancelled
                    $order->update_status('cancelled', sprintf(__('Payment cancelled. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
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
        $methods[] = 'WooCommerce_Payment_Gateway_App';
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
