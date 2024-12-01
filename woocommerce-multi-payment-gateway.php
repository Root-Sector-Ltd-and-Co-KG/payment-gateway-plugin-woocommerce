<?php
/**
 * Plugin Name: WooCommerce Multi Payment Gateway
 * Plugin URI: https://root-sector.com
 * Description: WooCommerce Multi Payment Gateway Extension.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Root Sector Ltd. & Co. KG
 * Author URI: https://root-sector.com
 * WC requires at least: 9.2
 * WC tested up to: 9.2
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */
defined('ABSPATH') || exit;

add_action('plugins_loaded', 'init_woocommerce_multi_payment_gateway', 0);

function init_woocommerce_multi_payment_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WooCommerce_Multi_Payment_Gateway extends WC_Payment_Gateway
    {
        protected $site_secret_key;
        protected $mpg_main_backend_domain;
        protected $currency;
        protected $debug;
        protected $log;

        public function __construct()
        {
            $this->id = 'multi_payment_gateway';
            $this->method_title = __('Multi Payment Gateway', 'woo-multi-payment-gateway');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->site_secret_key = $this->get_option('site_secret_key');
            $this->mpg_main_backend_domain = rtrim($this->get_option('mpg_main_backend_domain'), '/') . '/';
            $this->currency = get_woocommerce_currency();
            $this->debug = 'yes' === $this->get_option('debug', 'no');

            if ($this->debug) {
                $this->log = wc_get_logger();
            }

            $this->init_hooks();
        }

        private function init_hooks()
        {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_api_wc_multi_payment_gateway', array($this, 'webhook_response'));
            add_action('woocommerce_thankyou', array($this, 'validate_order_status'), 10, 1);
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woo-multi-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Multi Payment Gateway', 'woo-multi-payment-gateway'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woo-multi-payment-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woo-multi-payment-gateway'),
                    'default' => __('Secure Multi Payment Gateway', 'woo-multi-payment-gateway')
                ),
                'description' => array(
                    'title' => __('Description', 'woo-multi-payment-gateway'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woo-multi-payment-gateway'),
                    'default' => __('Pay securely with your credit card, debit card or bank account.', 'woo-multi-payment-gateway')
                ),
                'mpg_main_backend_domain' => array(
                    'title' => __('Default Main Backend Domain', 'woo-multi-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Your Multi Payment Gateway main backend domain without the protocol. For example, use "example.com" instead of "https://example.com".', 'woo-multi-payment-gateway'),
                    'default' => ''
                ),
                'site_secret_key' => array(
                    'title' => __('Site Secret Key', 'woo-multi-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Your Multi Payment Gateway site secret key.', 'woo-multi-payment-gateway'),
                    'default' => ''
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'woo-multi-payment-gateway'),
                    'type' => 'checkbox',
                    'description' => __('Enable logging of incoming and outgoing requests.', 'woo-multi-payment-gateway'),
                    'default' => 'no'
                )
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Sanitize and validate inputs
            $amount = round($order->get_total() * 100);
            $email = sanitize_email($order->get_billing_email());
            $cancelurl = esc_url($order->get_cancel_order_url());
            $returnurl = esc_url($this->get_return_url($order));
            $ipnurl = esc_url(add_query_arg('wc-api', 'wc_multi_payment_gateway', home_url('/', 'https')));

            if ($this->debug) {
                $this->log->debug('Creating Payment Session', array(
                    'source' => 'woocommerce-multi-payment-gateway',
                    'amount' => $amount,
                    'email' => $email,
                    'order_id' => $order_id,
                    'return_url' => $returnurl,
                    'cancel_url' => $cancelurl,
                    'ipn_url' => $ipnurl
                ));
            }

            $payment_session_url = 'https://' . $this->mpg_main_backend_domain . 'api/v1/sessions/create';

            $hashData = array(
                'amount' => $amount,
                'currency' => $this->currency,
                'email' => $email,
                'customInvoiceId' => (string) $order_id,
                'returnUrl' => $returnurl,
                'cancelUrl' => $cancelurl,
                'ipnUrl' => $ipnurl,
            );

            if ($this->debug) {
                $this->log->debug('Preparing to send request', array(
                    'source' => 'woocommerce-multi-payment-gateway',
                    'url' => $payment_session_url,
                    'parameters' => $hashData
                ));
            }

            $response = wp_remote_post(
                $payment_session_url,
                array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Site-Secret-Key' => $this->site_secret_key,
                    ),
                    'body' => json_encode($hashData),
                )
            );

            if ($this->debug) {
                $this->log->debug('Received response', array(
                    'source' => 'woocommerce-multi-payment-gateway',
                    'response' => $response
                ));
            }

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                if ($this->debug) {
                    $this->log->error('Request Error', array(
                        'source' => 'woocommerce-multi-payment-gateway',
                        'error_message' => $error_message
                    ));
                }
                wc_add_notice(__('Payment session creation failed. Error: ', 'woo-multi-payment-gateway') . $error_message, 'error');
                return array(
                    'result' => 'failure',
                    'redirect' => $cancelurl
                );
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if (406 === $response_code) {
                $response_body = json_decode(wp_remote_retrieve_body($response), true);
                $error_message = $response_body['error'] ?? __('an unexpected error', 'woo-multi-payment-gateway');
                wc_add_notice(__('Payment session creation failed. Error: ', 'woo-multi-payment-gateway') . $error_message, 'error');

                // Cancel the order
                $order->update_status('cancelled', __('Order cancelled due to payment gateway error. Reason: ', 'woo-multi-payment-gateway') . $error_message);

                return array(
                    'result' => 'failure',
                    'redirect' => $cancelurl
                );
            } elseif (200 !== $response_code) {
                if ($this->debug) {
                    $this->log->error('Unexpected Response Code', array(
                        'source' => 'woocommerce-multi-payment-gateway',
                        'response_code' => $response_code
                    ));
                }
                wc_add_notice(__('Payment session creation failed due to an unexpected response from the payment gateway.', 'woo-multi-payment-gateway'), 'error');

                return array(
                    'result' => 'failure',
                    'redirect' => $cancelurl
                );
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true);

            if ($this->debug) {
                $this->log->debug('Decoded response body', array(
                    'source' => 'woocommerce-multi-payment-gateway',
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
                    'source' => 'woocommerce-multi-payment-gateway',
                    'response_body' => $response_body
                ));
            }

            wc_add_notice(__('Payment session creation failed. Reason: ', 'woo-multi-payment-gateway') . $response_body['error'], 'error');
            return array(
                'result' => 'failure',
                'redirect' => $cancelurl
            );
        }

        public function receipt_page($order_id)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay.', 'woo-multi-payment-gateway') . '</p>';
            echo '<a class="button alt" href="' . esc_url($this->get_return_url(wc_get_order($order_id))) . '">' . __('Pay Now', 'woo-multi-payment-gateway') . '</a>';
        }

        public function webhook_response()
        {
            if ($this->debug) {
                $this->log->debug('Incoming webhook request', array(
                    'source' => 'woocommerce-multi-payment-gateway',
                    'headers' => getallheaders(),
                    'raw_body' => file_get_contents('php://input'),
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'query_params' => $_GET
                ));
            }

            $appSecret = $this->site_secret_key;

            // Validate JSON input
            $raw_body = file_get_contents('php://input');
            $parsed_request = json_decode($raw_body, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed_request)) {
                if ($this->debug) {
                    $this->log->error('Invalid JSON in webhook request', array(
                        'source' => 'woocommerce-multi-payment-gateway',
                        'raw_body' => $raw_body,
                        'json_error' => json_last_error_msg()
                    ));
                }
                status_header(400);
                exit("Invalid JSON");
            }

            $received_hash = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
            if (!$received_hash) {
                if ($this->debug) {
                    $this->log->error('Missing X-Signature header', array(
                        'source' => 'woocommerce-multi-payment-gateway',
                        'headers' => getallheaders()
                    ));
                }
                status_header(400);
                exit("Missing X-Signature header");
            }

            $hashData = array(
                'status' => $parsed_request['status'],
                'transactionId' => $parsed_request['transactionId'],
                'customInvoiceId' => $parsed_request['customInvoiceId'],
            );

            ksort($hashData);
            $hashString = json_encode($hashData);
            $computedHash = hash_hmac('sha256', $hashString, $appSecret);

            if (!hash_equals($computedHash, $received_hash)) {
                status_header(400);
                exit("Invalid signature");
            }

            $order = wc_get_order($parsed_request['customInvoiceId']);
            if (!$order) {
                status_header(404);
                exit("Order not found");
            }

            switch ($parsed_request['status']) {
                case "0":
                    $order->update_status('on-hold', sprintf(__('Payment pending. Transaction ID: %s', 'woo-multi-payment-gateway'), $parsed_request['transactionId']));
                    break;
                case "1":
                    $order->update_status('completed', sprintf(__('Payment completed. Transaction ID: %s', 'woo-multi-payment-gateway'), $parsed_request['transactionId']));
                    break;
                case "2":
                    $order->update_status('failed', sprintf(__('Payment failed. Transaction ID: %s', 'woo-multi-payment-gateway'), $parsed_request['transactionId']));
                    break;
                case "3":
                    $order->update_status('refunded', sprintf(__('Payment refunded. Transaction ID: %s', 'woo-multi-payment-gateway'), $parsed_request['transactionId']));
                    break;
                case "4":
                    $order->update_status('refunded', sprintf(__('Chargeback received. Transaction ID: %s', 'woo-multi-payment-gateway'), $parsed_request['transactionId']));
                    break;
            }

            status_header(200);
            exit("OK");
        }

        public function validate_order_status($order_id)
        {
            if (isset($_REQUEST['status']) && !empty($_REQUEST['status'])) {
                $appSecret = $this->site_secret_key;

                if ($this->isValidHash($_REQUEST, $appSecret)) {
                    $status = $_REQUEST['status'];

                    if ($this->debug) {
                        $this->log->debug('Incoming Request parameters (validate_order_status): ' . print_r($_REQUEST, true), array('source' => 'woocommerce-multi-payment-gateway'));
                    }

                    $order = wc_get_order($order_id);

                    switch ($status) {
                        case "0":
                            $order->reduce_order_stock();
                            wc()->cart->empty_cart();
                            wp_redirect($this->get_return_url($order));
                            break;
                        case "1":
                            $order->payment_complete();
                            wp_redirect($this->get_return_url($order));
                            break;
                        case "2":
                            $order->update_status('failed');
                            wp_redirect($order->get_cancel_order_url());
                            break;
                        case "3":
                            $order->update_status('refunded', __('Payment refunded', 'woo-multi-payment-gateway'));
                            wp_redirect($order->get_cancel_order_url());
                            break;
                        case "4":
                            $order->update_status('refunded');
                            break;
                    }
                } else {
                    if ($this->debug) {
                        $this->log->debug('isValidHash false $_REQUEST: ' . print_r($_REQUEST, true) . ' $appSecret: ' . $appSecret, array('source' => 'woocommerce-multi-payment-gateway'));
                    }
                    wc_add_notice(__('Transaction cancelled.', 'woo-multi-payment-gateway'), 'error');
                    wp_safe_redirect(wc_get_checkout_url());
                    exit;
                }
            }
        }

        public function isValidHash($data, $appSecret)
        {
            $hashData = array(
                'status' => $data['status'],
                'transactionid' => $data['transactionid'],
                'custominvoiceid' => $data['custominvoiceid'],
            );

            ksort($hashData);

            $hashString = implode('', $hashData);

            $computedHash = hash_hmac('sha256', $hashString, $appSecret);

            return hash_equals($computedHash, $data['hash']);
        }
    }

    function add_multi_payment_gateway($methods)
    {
        $methods[] = 'WooCommerce_Multi_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_multi_payment_gateway');
}

// Declare compatibility with HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});