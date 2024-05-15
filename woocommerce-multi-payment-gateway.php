<?php
/**
 * Plugin Name: WooCommerce multi-payment-gateway
 * Plugin URI: https://wordpress.org/plugins/woocommerce-multi-payment-gateway/
 * Description: Streamline your online payment process with our all-in-one multi payment gateway, enabling you to accept multiple payment methods effortlessly and delighting your customers with a hassle-free checkout experience.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Root Sector Ltd. & Co. KG
 * Author URI: https://root-sector.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */
add_action('plugins_loaded', 'init_woocommerce_multi_payment_gateway', 0);

add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
);

function init_woocommerce_multi_payment_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    load_plugin_textdomain('woo-multi-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/lang');

    class WooCommerce_Multi_Payment_Gateway extends WC_Payment_Gateway
    {
        protected $encryption_key;
        protected $payment_url;
        protected $currency;
        protected $debug;
        protected $log;
    
        public function __construct()
        {
            $this->id           = 'multi-payment-gateway';
            $this->method_title = __('Multi Payment Gateway', 'woo-multi-payment-gateway');
            $this->has_fields   = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title          = $this->get_option('title');
            $this->description    = $this->get_option('description');
            $this->encryption_key = $this->get_option('encryption_key');
            $this->payment_url    = rtrim($this->get_option('payment_url'), '/') . '/';
            $this->currency       = get_woocommerce_currency();
            $this->debug          = 'yes' === $this->get_option('debug', 'no');

            if ($this->debug) {
                $this->log = wc_get_logger();
            }

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_api_wc_multi_payment_gateway', array($this, 'webhook_response'));
            add_action('woocommerce_thankyou', array($this, 'validate_order_status'), 10, 1);
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled'        => array(
                    'title'   => __('Enable/Disable', 'woo-multi-payment-gateway'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Multi Payment Gateway', 'woo-multi-payment-gateway'),
                    'default' => 'yes'
                ),
                'title'          => array(
                    'title'       => __('Title', 'woo-multi-payment-gateway'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woo-multi-payment-gateway'),
                    'default'     => __('Secure Multi Payment Gateway', 'woo-multi-payment-gateway')
                ),
                'description'    => array(
                    'title'       => __('Description', 'woo-multi-payment-gateway'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woo-multi-payment-gateway'),
                    'default'     => __('Pay securely with your credit card, debit card or bank account.', 'woo-multi-payment-gateway')
                ),
                'payment_url'    => array(
                    'title'       => __('Payment System URL', 'woo-multi-payment-gateway'),
                    'type'        => 'text',
                    'description' => __('This is the payment URL of the Multi Payment Gateway. (Example: http://example.com/multi-payment-gateway/)', 'woo-multi-payment-gateway'),
                    'default'     => ''
                ),
                'encryption_key' => array(
                    'title'       => __('Encryption Key', 'woo-multi-payment-gateway'),
                    'type'        => 'text',
                    'description' => __('This is your Multi Payment Gateway encryption key.', 'woo-multi-payment-gateway'),
                    'default'     => ''
                ),
                'debug'          => array(
                    'title'       => __('Debug Log', 'woo-multi-payment-gateway'),
                    'type'        => 'checkbox',
                    'description' => __('Enable logging of incoming and outgoing requests.', 'woo-multi-payment-gateway'),
                    'default'     => 'no'
                )
            );
        }

        public function admin_options()
        {
            echo '<h2>' . esc_html__('Multi Payment Gateway', 'woo-multi-payment-gateway') . '</h2>';
            echo '<p>' . esc_html__('Multi Payment Gateway works by sending the user to the payment gateway to enter their payment information.', 'woo-multi-payment-gateway') . '</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        public function receipt_page($order_id)
        {
            $order = wc_get_order($order_id);

            if ($order) {
                $amount    = $order->get_total();
                $email     = $order->get_billing_email();
                $cancelurl = $order->get_cancel_order_url();
                $returnurl = $this->get_return_url($order);
                $ipnurl    = str_replace('https:', 'http:', add_query_arg('wc-api', 'wc_multi_payment_gateway', home_url('/')));

                $this->create_payment_session($amount, $email, $order_id, $returnurl, $cancelurl, $ipnurl);
            }
        }

        public function create_payment_session($amount, $email, $order_id, $returnurl, $cancelurl, $ipnurl)
        {
            $payment_session_url = $this->payment_url . 'create-payment-session.php';

            $hashData = array(
                'amount'          => $amount,
                'currency'        => $this->currency,
                'email'           => $email,
                'custominvoiceid' => $order_id,
                'returnurl'       => $returnurl,
                'cancelurl'       => $cancelurl,
                'ipnurl'          => $ipnurl,
            );

            ksort($hashData);

            $hashString = implode('', $hashData);

            $computedHash = hash_hmac('sha256', $hashString, $this->encryption_key);

            $hashData['hash'] = $computedHash;

            $response = wp_remote_post(
                $payment_session_url,
                array(
                    'body' => $hashData,
                )
            );

            if ($this->debug) {
                $this->log->debug('Outgoing Request parameters: ' . print_r($hashData, true) . ' URL: ' . $payment_session_url, array('source' => 'woocommerce-multi-payment-gateway'));
            }

            if (!is_wp_error($response) && 201 === wp_remote_retrieve_response_code($response)) {
                $response_body = json_decode(wp_remote_retrieve_body($response), true);

                if (isset($response_body['sid'])) {
                    $payment_url = $this->payment_url . 'index.php?sid=' . $response_body['sid'];

                    wp_redirect($payment_url);
                    exit;
                }
            }

            if ($this->debug) {
                $this->log->debug('Outgoing Request failed: Response: ' . print_r($response, true) . ' URL: ' . $payment_session_url, array('source' => 'woocommerce-multi-payment-gateway'));
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            wc_add_notice(__('Payment session creation failed. Reason: ', 'woo-multi-payment-gateway') . $response_body['error'], 'error');
            wp_redirect($cancelurl);
            exit;
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $order->update_status('pending', __('Awaiting payment', 'woo-multi-payment-gateway'));

            wc()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        public function validate_order_status($order_id)
        {
            if (isset($_REQUEST['status']) && !empty($_REQUEST['status'])) {
                $appSecret = $this->encryption_key;

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
                            $order->update_status('refunded');
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

        public function webhook_response()
        {
            $appSecret = $this->encryption_key;

            if ($this->isValidHash($_REQUEST, $appSecret)) {
                $status          = $_REQUEST['status'];
                $transactionid   = $_REQUEST['transactionid'];
                $custominvoiceid = $_REQUEST['custominvoiceid'];

                if ($this->debug) {
                    $this->log->debug('Incoming Request parameters (webhook_response): ' . print_r($_REQUEST, true), array('source' => 'woocommerce-multi-payment-gateway'));
                }

                $order = wc_get_order($custominvoiceid);

                switch ($status) {
                    case "0":
                        $order->update_status('on-hold', sprintf(__('Multi Payment Gateway payment pending. Transaction ID: %s', 'woo-multi-payment-gateway'), $transactionid));
                        $order->reduce_order_stock();
                        wc()->cart->empty_cart();
                        break;
                    case "1":
                        $order->add_order_note(sprintf(__('Multi Payment Gateway payment completed. Transaction ID: %s', 'woo-multi-payment-gateway'), $transactionid));
                        $order->payment_complete($transactionid);
                        break;
                    case "2":
                        $order->add_order_note(sprintf(__('Multi Payment Gateway payment failed. Transaction ID: %s', 'woo-multi-payment-gateway'), $transactionid));
                        $order->update_status('failed');
                        break;
                    case "3":
                        $order->update_status('refunded', sprintf(__('Multi Payment Gateway payment refund received. Transaction ID: %s', 'woo-multi-payment-gateway'), $transactionid));
                        break;
                    case "4":
                        $order->update_status('refunded', sprintf(__('Multi Payment Gateway payment chargeback received. Transaction ID: %s', 'woo-multi-payment-gateway'), $transactionid));
                        break;
                }
            } else {
                if ($this->debug) {
                    $this->log->debug('isValidHash false $_REQUEST: ' . print_r($_REQUEST, true) . ' $appSecret: ' . $appSecret, array('source' => 'woocommerce-multi-payment-gateway'));
                }
                exit;
            }
        }

        public function isValidHash($data, $appSecret)
        {
            $hashData = array(
                'status'          => $data['status'],
                'transactionid'   => $data['transactionid'],
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