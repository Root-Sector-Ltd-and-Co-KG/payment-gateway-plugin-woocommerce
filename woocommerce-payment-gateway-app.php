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

final class WC_Payment_Gateway_App_Api_Error_Context
{
    const MAX_IDENTIFIER_LENGTH = 128;
    const MAX_PROVIDER_LENGTH = 64;
    const MAX_PROVIDER_COUNT = 20;

    public static function parse($response_body, $http_status = null)
    {
        $data = is_array($response_body) ? $response_body : array();
        return array(
            'http_status' => is_numeric($http_status) ? (int)$http_status : null,
            'code' => self::identifier(self::scalar($data, array('code', 'error.code'))),
            'request_id' => self::identifier(self::scalar($data, array('requestId', 'requestID', 'error.requestId', 'error.requestID', 'chargeback.requestId', 'chargeback.requestID', 'error.chargeback.requestId', 'error.chargeback.requestID'))),
            'transaction_id' => self::identifier(self::scalar($data, array('id', 'transactionId', 'error.id', 'error.transactionId', 'chargeback.transactionId', 'chargeback.gatewayTransactionId', 'error.chargeback.transactionId', 'error.chargeback.gatewayTransactionId'))),
            'external_reference' => self::identifier(self::scalar($data, array('externalReference', 'error.externalReference', 'chargeback.externalReference', 'error.chargeback.externalReference'))),
            'amount' => self::numeric_value(self::value($data, array('amount', 'error.amount'))),
            'currency' => self::identifier(self::scalar($data, array('currency', 'error.currency'))),
            'dispute_date' => self::identifier(self::scalar($data, array('disputeDate', 'transactionDate', 'error.disputeDate', 'error.transactionDate'))),
            'gateway_status' => self::identifier(self::scalar($data, array('status', 'error.status'))),
            'dispute_id' => self::identifier(self::scalar($data, array('disputeId', 'chargebackId', 'error.disputeId', 'error.chargebackId', 'chargeback.disputeId', 'chargeback.id', 'error.chargeback.disputeId', 'error.chargeback.id'))),
            'dispute_status' => self::identifier(self::scalar($data, array('disputeStatus', 'error.disputeStatus', 'chargeback.disputeStatus', 'chargeback.status', 'error.chargeback.disputeStatus', 'error.chargeback.status'))),
            'chargeback_status' => self::identifier(self::scalar($data, array('chargebackStatus', 'error.chargebackStatus', 'chargeback.chargebackStatus', 'error.chargeback.chargebackStatus'))),
            'credit_note_id' => self::identifier(self::scalar($data, array('creditNoteId', 'error.creditNoteId', 'chargeback.creditNoteId', 'creditNote.id', 'error.chargeback.creditNoteId', 'error.creditNote.id'))),
            'credit_note_number' => self::identifier(self::scalar($data, array('creditNoteNumber', 'error.creditNoteNumber', 'chargeback.creditNoteNumber', 'creditNote.number', 'error.chargeback.creditNoteNumber', 'error.creditNote.number'))),
            'customer_risk_hold_id' => self::identifier(self::scalar($data, array('customerRiskHoldId', 'customerRiskHold.id', 'error.customerRiskHoldId', 'error.customerRiskHold.id'))),
            'customer_risk_action' => self::action(self::scalar($data, array('customerRiskAction', 'customerRiskHold.action', 'error.customerRiskAction', 'error.customerRiskHold.action'))),
            'customer_risk_reason' => self::identifier(self::scalar($data, array('customerRiskReason', 'customerRiskHold.reason', 'error.customerRiskReason', 'error.customerRiskHold.reason'))),
            'allowed_provider_types' => self::identifier_list(self::array_value($data, array('allowedProviderTypes', 'customerRiskHold.allowedProviderTypes', 'error.allowedProviderTypes', 'error.customerRiskHold.allowedProviderTypes'))),
            'allowed_provider_ids' => self::identifier_list(self::array_value($data, array('allowedProviderIds', 'customerRiskHold.allowedProviderIds', 'error.allowedProviderIds', 'error.customerRiskHold.allowedProviderIds'))),
        );
    }

    public static function customer_message(array $context, $fallback, array $messages = array())
    {
        $messages += array(
            'CHECKOUT_BLOCKED_BY_DISPUTE' => 'Payment cannot be started because an unresolved dispute is being reviewed. Please contact support.',
            'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD' => 'Payment cannot be started because this customer account is under merchant review. Please contact support.',
            'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD' => 'Only bank transfer payment methods are available for this account. Please choose an available bank transfer option or contact support.',
        );
        $code = isset($context['code']) ? (string)$context['code'] : '';
        $message = isset($messages[$code]) ? $messages[$code] : trim((string)$fallback);
        if ($message === '') {
            $message = 'Payment session creation failed due to an unexpected gateway response.';
        }
        if (!empty($context['request_id'])) {
            $message .= ' Request ID: ' . $context['request_id'];
        }
        return $message;
    }

    public static function log_context(array $context, array $extra = array())
    {
        $allowed = array('http_status', 'code', 'request_id', 'transaction_id', 'external_reference', 'amount', 'currency', 'dispute_date', 'gateway_status', 'dispute_id', 'dispute_status', 'chargeback_status', 'credit_note_id', 'credit_note_number', 'customer_risk_hold_id', 'customer_risk_action', 'customer_risk_reason', 'allowed_provider_types', 'allowed_provider_ids');
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === '' || $context[$key] === null || $context[$key] === array()) {
                continue;
            }
            $extra[$key] = $context[$key];
        }
        return $extra;
    }

    private static function value(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    continue 2;
                }
                $value = $value[$part];
            }
            return $value;
        }
        return null;
    }

    private static function scalar(array $data, array $paths)
    {
        $value = self::value($data, $paths);
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private static function array_value(array $data, array $paths)
    {
        $value = self::value($data, $paths);
        return is_array($value) ? $value : array();
    }

    private static function identifier($value, $max_length = self::MAX_IDENTIFIER_LENGTH)
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $max_length || !preg_match('/\A[A-Za-z0-9._:-]+\z/', $value)) {
            return '';
        }
        return $value;
    }

    private static function action($value)
    {
        $value = self::identifier($value);
        return in_array($value, array('block_all', 'manual_review', 'allow_provider_types'), true) ? $value : '';
    }

    private static function identifier_list(array $values)
    {
        $result = array();
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $identifier = self::identifier($value, self::MAX_PROVIDER_LENGTH);
            if ($identifier === '' || in_array($identifier, $result, true)) {
                continue;
            }
            $result[] = $identifier;
            if (count($result) >= self::MAX_PROVIDER_COUNT) {
                break;
            }
        }
        return $result;
    }

    private static function numeric_value($value)
    {
        return is_numeric($value) ? $value + 0 : null;
    }
}

final class WC_Payment_Gateway_App_IPN_Request
{
    const SIGNATURE_TOLERANCE_SECONDS = 300;
    const MAX_DELIVERY_ID_LENGTH = 128;

    public static function verify($raw_body, array $headers, $webhook_secret, $now = null)
    {
        $raw_body = is_string($raw_body) ? $raw_body : '';
        $timestamp = isset($headers['timestamp']) ? trim((string) $headers['timestamp']) : '';
        $signature = isset($headers['signature']) ? trim((string) $headers['signature']) : '';
        $now = $now === null ? time() : (int) $now;

        if ($timestamp === '' || $signature === '') {
            return self::failure('signature_headers_missing');
        }
        if (!preg_match('/\A[0-9]+\z/', $timestamp)) {
            return self::failure('invalid_signature_timestamp');
        }
        if (abs($now - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return self::failure('signature_timestamp_outside_tolerance');
        }
        if (!preg_match('/\A[a-fA-F0-9]{64}\z/', $signature)) {
            return self::failure('invalid_signature');
        }

        $expected_signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, (string) $webhook_secret);
        if (!hash_equals($expected_signature, strtolower($signature))) {
            return self::failure('invalid_signature');
        }

        $payload = json_decode($raw_body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            return self::failure('invalid_json');
        }

        $version_header = isset($headers['version']) ? trim((string) $headers['version']) : '';
        if ($version_header === '') {
            if (array_key_exists('schemaVersion', $payload)) {
                return self::failure('version_mismatch');
            }
            return self::success(1, $payload);
        }
        if ($version_header === '1') {
            if (isset($payload['schemaVersion']) && (int) $payload['schemaVersion'] !== 1) {
                return self::failure('version_mismatch');
            }
            return self::success(1, $payload);
        }
        if ($version_header !== '2') {
            return self::failure('unsupported_ipn_version');
        }

        if (!isset($payload['schemaVersion']) || !is_int($payload['schemaVersion']) || $payload['schemaVersion'] !== 2) {
            return self::failure('invalid_schema_version');
        }
        if (!isset($payload['deliveryId']) || !self::valid_identifier($payload['deliveryId'])) {
            return self::failure('invalid_delivery_id');
        }

        $header_delivery_id = isset($headers['delivery_id']) ? trim((string) $headers['delivery_id']) : '';
        if (!self::valid_identifier($header_delivery_id)) {
            return self::failure('invalid_delivery_id');
        }
        if (!hash_equals((string) $payload['deliveryId'], $header_delivery_id)) {
            return self::failure('delivery_id_mismatch');
        }
        if (!isset($payload['eventVersion']) || !is_int($payload['eventVersion']) || $payload['eventVersion'] <= 0) {
            return self::failure('invalid_event_version');
        }
        if (!isset($payload['occurredAt']) || !self::valid_occurred_at($payload['occurredAt'])) {
            return self::failure('invalid_occurred_at');
        }
        foreach (array('transactionId', 'gatewayTransactionId', 'external_reference', 'paymentStatus', 'disputeStatus', 'chargebackStatus', 'chargeback') as $legacy_alias) {
            if (array_key_exists($legacy_alias, $payload)) {
                return self::failure('legacy_alias_not_allowed');
            }
        }
        if (!self::valid_payload_identity($payload['id'] ?? null)) {
            return self::failure('invalid_transaction_id');
        }
        if (!self::valid_payload_identity($payload['externalReference'] ?? null)) {
            return self::failure('invalid_external_reference');
        }
        if (
            !isset($payload['status'])
            || !is_int($payload['status'])
            || !in_array($payload['status'], array(-2, -1, 0, 1, 2, 3, 4), true)
        ) {
            return self::failure('invalid_status');
        }

        return self::success(2, $payload);
    }

    private static function valid_identifier($value)
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_DELIVERY_ID_LENGTH
            && preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) === 1;
    }

    private static function valid_payload_identity($value)
    {
        return is_string($value)
            && trim($value) !== ''
            && strlen($value) <= self::MAX_DELIVERY_ID_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function valid_occurred_at($value)
    {
        if (!is_string($value) || strlen($value) > 64) {
            return false;
        }
        if (!preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))\z/',
            $value,
            $parts
        )) {
            return false;
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        $hour = (int) $parts[4];
        $minute = (int) $parts[5];
        $second = (int) $parts[6];
        $offset_hour = isset($parts[7]) && $parts[7] !== '' ? (int) $parts[7] : 0;
        $offset_minute = isset($parts[8]) && $parts[8] !== '' ? (int) $parts[8] : 0;

        return checkdate($month, $day, $year)
            && $hour <= 23
            && $minute <= 59
            && $second <= 59
            && $offset_hour <= 23
            && $offset_minute <= 59;
    }

    private static function success($version, array $payload)
    {
        return array(
            'ok' => true,
            'version' => (int) $version,
            'payload' => $payload,
        );
    }

    private static function failure($code)
    {
        return array(
            'ok' => false,
            'code' => (string) $code,
        );
    }
}

final class WC_Payment_Gateway_App_IPN_V2_Processor
{
    const STATE_FORMAT_VERSION = 1;
    const MAX_RETAINED_DELIVERIES = 100;

    public static function meta_key($transaction_id)
    {
        return '_payment_gateway_app_ipn_v2_' . hash('sha256', (string) $transaction_id);
    }

    public static function process($order, $transaction_id, array $payload, $raw_body, callable $apply_effect)
    {
        $meta_key = self::meta_key($transaction_id);
        $state = self::load_state($order->get_meta($meta_key, true));
        $delivery_id = (string) $payload['deliveryId'];
        $event_version = (int) $payload['eventVersion'];
        $body_hash = hash('sha256', (string) $raw_body);

        if (isset($state['deliveries'][$delivery_id])) {
            $delivery = $state['deliveries'][$delivery_id];
            if (
                !isset($delivery['eventVersion'], $delivery['bodyHash'], $delivery['phase'])
                || (int) $delivery['eventVersion'] !== $event_version
                || !hash_equals((string) $delivery['bodyHash'], $body_hash)
            ) {
                return 'conflict';
            }
            if ($delivery['phase'] === 'applied') {
                return 'duplicate';
            }
            if ($delivery['phase'] === 'superseded') {
                return 'outdated';
            }
            if ($delivery['phase'] !== 'pending') {
                throw new UnexpectedValueException('Invalid IPN delivery phase.');
            }
            if ($event_version < $state['highestEventVersion']) {
                $state['deliveries'][$delivery_id]['phase'] = 'superseded';
                self::save_state($order, $meta_key, $state);
                return 'outdated';
            }
        } else {
            if ($event_version <= $state['highestEventVersion']) {
                return 'outdated';
            }
            $state['highestEventVersion'] = $event_version;
            $state['deliveries'][$delivery_id] = array(
                'eventVersion' => $event_version,
                'bodyHash' => $body_hash,
                'phase' => 'pending',
            );
            self::save_state($order, $meta_key, $state);
        }

        $apply_effect($order, $payload);

        $state = self::load_state($order->get_meta($meta_key, true));
        if (!isset($state['deliveries'][$delivery_id])) {
            throw new UnexpectedValueException('IPN delivery state disappeared during processing.');
        }
        $state['deliveries'][$delivery_id]['phase'] = 'applied';
        $state = self::trim_deliveries($state);
        self::save_state($order, $meta_key, $state);
        return 'applied';
    }

    private static function load_state($stored_state)
    {
        if ($stored_state === '' || $stored_state === null || $stored_state === false) {
            return array(
                'formatVersion' => self::STATE_FORMAT_VERSION,
                'highestEventVersion' => 0,
                'deliveries' => array(),
            );
        }
        if (
            !is_array($stored_state)
            || !isset($stored_state['formatVersion'], $stored_state['highestEventVersion'], $stored_state['deliveries'])
            || (int) $stored_state['formatVersion'] !== self::STATE_FORMAT_VERSION
            || !is_int($stored_state['highestEventVersion'])
            || $stored_state['highestEventVersion'] < 0
            || !is_array($stored_state['deliveries'])
        ) {
            throw new UnexpectedValueException('Invalid persisted IPN v2 state.');
        }
        return $stored_state;
    }

    private static function save_state($order, $meta_key, array $state)
    {
        $order->update_meta_data($meta_key, $state);
        $order->save();
    }

    private static function trim_deliveries(array $state)
    {
        while (count($state['deliveries']) > self::MAX_RETAINED_DELIVERIES) {
            $removed = false;
            foreach ($state['deliveries'] as $delivery_id => $delivery) {
                if (isset($delivery['phase']) && $delivery['phase'] === 'applied') {
                    unset($state['deliveries'][$delivery_id]);
                    $removed = true;
                    break;
                }
            }
            if (!$removed) {
                break;
            }
        }
        return $state;
    }
}

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

        private function format_customer_api_error($context, $fallback)
        {
            return WC_Payment_Gateway_App_Api_Error_Context::customer_message($context, $fallback, array(
                'CHECKOUT_BLOCKED_BY_DISPUTE' => __('Payment cannot be started because an unresolved dispute is being reviewed. Please contact support.', 'woo-payment-gateway-app'),
                'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD' => __('Payment cannot be started because this customer account is under merchant review. Please contact support.', 'woo-payment-gateway-app'),
                'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD' => __('Only bank transfer payment methods are available for this account. Please choose an available bank transfer option or contact support.', 'woo-payment-gateway-app'),
            ));
        }

        private function get_parsed_scalar($data, $paths)
        {
            foreach ($paths as $path) {
                $value = $data;
                foreach (explode('.', $path) as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        $value = null;
                        break;
                    }
                    $value = $value[$segment];
                }
                if (is_scalar($value)) {
                    return sanitize_text_field((string)$value);
                }
            }
            return '';
        }

        private function get_dispute_status($parsed_request)
        {
            foreach (array('disputeStatus', 'chargebackStatus', 'status', 'chargeback.status', 'chargeback.disputeStatus', 'chargeback.chargebackStatus') as $key) {
                $status = strtolower($this->get_parsed_scalar($parsed_request, array($key)));
                if ($this->is_supported_dispute_status($status)) {
                    return $status;
                }
            }
            return '';
        }

        private function is_supported_dispute_status($status)
        {
            return in_array($status, array('open', 'under_review', 'won', 'lost', 'accepted'), true);
        }

        private function is_chargeback_dispute_status($status)
        {
            return in_array($status, array('open', 'under_review', 'lost', 'accepted'), true);
        }

        private function get_gateway_transaction_id($parsed_request)
        {
            return $this->get_parsed_scalar($parsed_request, array('id', 'transactionId', 'chargeback.transactionId', 'chargeback.gatewayTransactionId'));
        }

        private function get_external_reference($parsed_request)
        {
            return $this->get_parsed_scalar($parsed_request, array('externalReference', 'chargeback.externalReference'));
        }

        private function get_safe_gateway_context($data, $extra = array())
        {
            return WC_Payment_Gateway_App_Api_Error_Context::log_context(
                WC_Payment_Gateway_App_Api_Error_Context::parse($data),
                $extra
            );
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

        private function get_dispute_event_key($parsed_request, $dispute_status, $transaction_id)
        {
            $dispute_id = $this->get_parsed_scalar($parsed_request, array('disputeId', 'chargebackId', 'chargeback.disputeId', 'chargeback.chargebackId', 'chargeback.id'));
            if ($dispute_id !== '') {
                return $transaction_id . '|dispute|' . $dispute_id . '|' . $dispute_status;
            }
            $request_id = $this->get_parsed_scalar($parsed_request, array('requestId', 'requestID', 'chargeback.requestId', 'chargeback.requestID'));
            if ($request_id !== '') {
                return $transaction_id . '|request|' . $request_id . '|' . $dispute_status;
            }
            return $transaction_id . '|status|' . $dispute_status;
        }

        private function add_dispute_order_note($order, $parsed_request, $dispute_status, $transaction_id)
        {
            $event_key = $this->get_dispute_event_key($parsed_request, $dispute_status, $transaction_id);
            $processed_events = $order->get_meta('_payment_gateway_app_dispute_events', true);
            if (!is_array($processed_events)) {
                $processed_events = array();
            }
            if (in_array($event_key, $processed_events, true)) {
                if ($this->debug) {
                    $this->log->info('Duplicate dispute webhook note skipped', $this->get_safe_gateway_context($parsed_request, array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'order_id' => $order->get_id(),
                        'event_key' => $event_key,
                    )));
                }
                return false;
            }

            $parts = array(sprintf(__('Payment Gateway App dispute update: %s. Transaction ID: %s', 'woo-payment-gateway-app'), strtoupper($dispute_status), $transaction_id));
            $dispute_id = $this->get_parsed_scalar($parsed_request, array('disputeId', 'chargebackId', 'chargeback.disputeId', 'chargeback.chargebackId', 'chargeback.id'));
            $request_id = $this->get_parsed_scalar($parsed_request, array('requestId', 'requestID', 'chargeback.requestId', 'chargeback.requestID'));
            $credit_note_number = $this->get_parsed_scalar($parsed_request, array('creditNoteNumber', 'chargeback.creditNoteNumber', 'creditNote.number'));
            if ($dispute_id !== '') {
                $parts[] = sprintf(__('Dispute ID: %s', 'woo-payment-gateway-app'), $dispute_id);
            }
            if ($request_id !== '') {
                $parts[] = sprintf(__('Request ID: %s', 'woo-payment-gateway-app'), $request_id);
            }
            if ($credit_note_number !== '') {
                $parts[] = sprintf(__('Credit note: %s', 'woo-payment-gateway-app'), $credit_note_number);
            }
            $order->add_order_note(implode(' | ', $parts));
            $processed_events[] = $event_key;
            if (count($processed_events) > 50) {
                $processed_events = array_slice($processed_events, -50);
            }
            $order->update_meta_data('_payment_gateway_app_dispute_events', $processed_events);
            $order->save();

            if ($this->debug) {
                $this->log->info('Dispute webhook update', $this->get_safe_gateway_context($parsed_request, array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'order_id' => $order->get_id(),
                    'transaction_id' => $transaction_id,
                    'dispute_status' => $dispute_status,
                )));
            }
            return true;
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
                    'description' => __('Write safe request/response metadata to WooCommerce logs.', 'woo-payment-gateway-app'),
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
                    'has_email' => $email !== '',
                    'has_return_url' => $returnurl !== '',
                    'has_cancel_url' => $cancelurl !== '',
                    'has_ipn_url' => $ipnurl !== ''
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
                $this->log->debug('Preparing to send request', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'url' => $payment_session_url,
                    'checkout' => $this->get_safe_checkout_request_context($hashData),
                ));
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

            if ($this->debug && !is_wp_error($response)) {
                $this->log->debug('Received response', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'response_code' => wp_remote_retrieve_response_code($response),
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
            $error_context = WC_Payment_Gateway_App_Api_Error_Context::parse($response_body, $response_code);

            if ($response_code !== 200) {
                $error_message = $this->format_customer_api_error(
                    $error_context,
                    __('Payment session creation failed due to an unexpected error.', 'woo-payment-gateway-app')
                );

                // Check for the specific rounding error and provide a more user-friendly message.
                if (strpos($error_message, 'sum of item amounts') !== false && strpos($error_message, 'does not match total amount') !== false) {
                    $error_message = __('The total amount of the items does not match the order total. This can be caused by a rounding difference. Please contact support for assistance.', 'woo-payment-gateway-app');
                }
                wc_add_notice(__('Payment session creation failed. Error: ', 'woo-payment-gateway-app') . $error_message, 'error');

                if ($this->debug) {
                    $this->log->error('Unexpected Response Code', WC_Payment_Gateway_App_Api_Error_Context::log_context($error_context, array(
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
                $this->log->debug('Decoded response body', array(
                    'source' => 'woocommerce-payment-gateway-app',
                    'response_code' => $response_code,
                    'has_payment_url' => isset($response_body['paymentUrl']),
                ));
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
                    'reason' => 'missing_payment_url',
                )));
            }

            $error_message = $this->format_customer_api_error(
                $error_context,
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

            $verified_request = WC_Payment_Gateway_App_IPN_Request::verify(
                $raw_body,
                array(
                    'timestamp' => $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null,
                    'signature' => $_SERVER['HTTP_X_SIGNATURE_HMAC_SHA256'] ?? null,
                    'version' => $_SERVER['HTTP_X_IPN_VERSION'] ?? null,
                    'delivery_id' => $_SERVER['HTTP_X_IPN_DELIVERY_ID'] ?? null,
                ),
                $this->webhook_secret
            );
            if (!$verified_request['ok']) {
                if ($this->debug) {
                    $this->log->error('Webhook request rejected', array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'reason' => $verified_request['code'],
                    ));
                }
                status_header(400);
                exit("Invalid webhook request");
            }
            $parsed_request = $verified_request['payload'];

            $dispute_status = $this->get_dispute_status($parsed_request);
            $has_dispute_status = $this->is_supported_dispute_status($dispute_status);
            $external_reference = $this->get_external_reference($parsed_request);
            $transaction_id = $this->get_gateway_transaction_id($parsed_request);
            if ($external_reference === '' || $transaction_id === '' || ((!isset($parsed_request['status']) || !is_numeric($parsed_request['status'])) && !$has_dispute_status)) {
                if ($this->debug) {
                    $this->log->error('Missing or invalid webhook fields', $this->get_safe_gateway_context($parsed_request, array(
                        'source' => 'woocommerce-payment-gateway-app',
                        'reason' => 'missing_or_invalid_fields',
                    )));
                }
                status_header(400);
                exit("Missing required fields");
            }

            $order = wc_get_order($external_reference);
            if (!$order) {
                status_header(404);
                exit("Order not found");
            }

            if ($verified_request['version'] === 2) {
                $lock_name = $this->acquire_ipn_v2_lock($order->get_id(), $transaction_id);
                if ($lock_name === '') {
                    if ($this->debug) {
                        $this->log->warning('Webhook delivery lock unavailable', $this->get_safe_gateway_context($parsed_request, array(
                            'source' => 'woocommerce-payment-gateway-app',
                            'delivery_id' => $parsed_request['deliveryId'],
                        )));
                    }
                    status_header(503);
                    exit("Webhook temporarily unavailable");
                }

                $processing_result = '';
                try {
                    // Re-read through WooCommerce CRUD after taking the lock so HPOS and
                    // posts-table stores both observe the latest delivery state.
                    $order = wc_get_order($external_reference);
                    if (!$order) {
                        $processing_result = 'order_not_found';
                    } else {
                        $processing_result = WC_Payment_Gateway_App_IPN_V2_Processor::process(
                            $order,
                            $transaction_id,
                            $parsed_request,
                            $raw_body,
                            function ($locked_order, array $event) use ($dispute_status, $transaction_id) {
                                $this->apply_webhook_effect($locked_order, $event, $dispute_status, $transaction_id, true);
                            }
                        );
                    }
                } catch (Throwable $error) {
                    if ($this->debug) {
                        $this->log->error('Webhook delivery processing failed', $this->get_safe_gateway_context($parsed_request, array(
                            'source' => 'woocommerce-payment-gateway-app',
                            'delivery_id' => $parsed_request['deliveryId'],
                            'error_type' => get_class($error),
                        )));
                    }
                    $processing_result = 'temporary_failure';
                } finally {
                    $this->release_ipn_v2_lock($lock_name);
                }

                if ($processing_result === 'order_not_found') {
                    status_header(404);
                    exit("Order not found");
                }
                if ($processing_result === 'conflict') {
                    status_header(409);
                    exit("Delivery identity conflict");
                }
                if ($processing_result === 'temporary_failure') {
                    status_header(503);
                    exit("Webhook temporarily unavailable");
                }
                status_header(200);
                exit("OK");
            }

            $this->apply_webhook_effect($order, $parsed_request, $dispute_status, $transaction_id, false);
            status_header(200);
            exit("OK");
        }

        private function apply_webhook_effect($order, array $parsed_request, $dispute_status, $transaction_id, $idempotent_recovery)
        {
            $has_dispute_status = $this->is_supported_dispute_status($dispute_status);
            if ($has_dispute_status) {
                $this->add_dispute_order_note($order, $parsed_request, $dispute_status, $transaction_id);
                if ($this->is_chargeback_dispute_status($dispute_status)) {
                    $this->update_order_status(
                        $order,
                        'refunded',
                        sprintf(__('Chargeback/dispute %s received. Transaction ID: %s', 'woo-payment-gateway-app'), $dispute_status, $transaction_id),
                        $idempotent_recovery
                    );
                }
                return;
            }

            $status = isset($parsed_request['status']) && is_numeric($parsed_request['status']) ? (int) $parsed_request['status'] : null;
            switch ($status) {
                case 0: // Pending
                    $this->update_order_status($order, 'on-hold', sprintf(__('Payment pending. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id), $idempotent_recovery);
                    break;
                case 1: // Completed
                    if (!$order->is_paid()) {
                        $order->payment_complete($transaction_id);
                        $order->add_order_note(sprintf(__('Payment completed. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id));
                    }
                    break;
                case 2: // Failed
                    $this->update_order_status($order, 'failed', sprintf(__('Payment failed. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id), $idempotent_recovery);
                    break;
                case 3: // Refunded
                    $this->update_order_status($order, 'refunded', sprintf(__('Payment refunded. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id), $idempotent_recovery);
                    break;
                case 4: // Chargeback/Disputed
                    if ($dispute_status === 'won') {
                        break;
                    }
                    $this->update_order_status($order, 'refunded', sprintf(__('Chargeback received. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id), $idempotent_recovery);
                    break;
                case -1: // Initiated
                    $this->update_order_status($order, 'on-hold', sprintf(__('Payment initiated. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id), $idempotent_recovery);
                    break;
                case -2: // Cancelled
                    $this->update_order_status($order, 'cancelled', sprintf(__('Payment cancelled. Transaction ID: %s', 'woo-payment-gateway-app'), $transaction_id), $idempotent_recovery);
                    break;
            }
        }

        private function update_order_status($order, $status, $note, $idempotent_recovery)
        {
            if ($idempotent_recovery && $order->get_status() === $status) {
                return;
            }
            $order->update_status($status, $note);
        }

        private function acquire_ipn_v2_lock($order_id, $transaction_id)
        {
            global $wpdb;
            if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
                return '';
            }
            $lock_name = 'mpg_ipn_v2_' . hash('sha1', (string) $order_id . '|' . (string) $transaction_id);
            $query = $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5);
            $acquired = $wpdb->get_var($query);
            return (string) $acquired === '1' ? $lock_name : '';
        }

        private function release_ipn_v2_lock($lock_name)
        {
            global $wpdb;
            if ($lock_name === '' || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
                return;
            }
            $query = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name);
            $wpdb->get_var($query);
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
