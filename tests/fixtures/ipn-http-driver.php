<?php
// Separate process because the real WordPress webhook terminates its response.
define('ABSPATH', __DIR__);
class WC_Payment_Gateway {
    public $id, $method_title, $has_fields, $title, $description, $form_fields;
    public function init_settings() {}
    public function get_option($key, $default = '') { return $key === 'webhook_secret' ? 'test-http-secret' : $default; }
}
function add_action() {}
function add_filter() {}
function __($text) { return $text; }
function sanitize_text_field($text) { return trim((string)$text); }
function get_woocommerce_currency() { return 'EUR'; }
function status_header($status) { http_response_code($status); }
function wc_get_order($id) { $GLOBALS['lookups']++; return $GLOBALS['order']; }
class HttpOrder {
    public $status = 'pending', $transactionId = '', $meta = array();
    public function get_id() { return 42; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save() {}
    public function get_transaction_id() { return $this->transactionId; }
    public function payment_complete($id) { $this->transactionId = $id; $this->status = 'processing'; }
    public function update_status($status, $note = '') { $this->status = $status; }
}
class HttpDb {
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) { return '1'; }
}
class HttpInput {
    public $context;
    private $position = 0;
    public function stream_open($path, $mode, $options, &$opened) { return $path === 'php://input'; }
    public function stream_read($count) { $data = substr($GLOBALS['input'], $this->position, $count); $this->position += strlen($data); return $data; }
    public function stream_eof() { return $this->position >= strlen($GLOBALS['input']); }
    public function stream_stat() { return array(); }
}
require dirname(__DIR__, 2) . '/woocommerce-payment-gateway-app.php';
init_woocommerce_payment_gateway_app();
$gateway = new WooCommerce_Payment_Gateway_App();
$GLOBALS['lookups'] = 0;
$GLOBALS['order'] = new HttpOrder();
$wpdb = new HttpDb();
$case = $argv[1];
$payload = array('schemaVersion' => 2, 'eventType' => 'ipn.test', 'deliveryId' => 'http-probe/id', 'occurredAt' => '2026-08-31T18:00:00Z');
if ($case === 'hybrid') { $payload['status'] = 1; }
if ($case === 'migration') {
    $paid = array('schemaVersion' => 2, 'deliveryId' => 'http-paid', 'eventVersion' => 2, 'occurredAt' => '2026-08-31T18:00:00Z', 'id' => 'transaction-http', 'externalReference' => '42', 'status' => 1);
    WC_Payment_Gateway_App_IPN_V2_Processor::process($GLOBALS['order'], 'transaction-http', $paid, json_encode($paid), static function ($order) { $order->payment_complete('transaction-http'); });
    $payload = array('id' => 'transaction-http', 'externalReference' => '42', 'status' => 2);
}
$GLOBALS['input'] = json_encode($payload);
$timestamp = (string)time();
$_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_X_SIGNATURE_HMAC_SHA256'] = hash_hmac('sha256', $timestamp . '.' . $GLOBALS['input'], 'test-http-secret');
if ($case !== 'migration') {
    $_SERVER['HTTP_X_IPN_VERSION'] = $case === 'unknown-version' ? '3' : '2';
    $_SERVER['HTTP_X_IPN_DELIVERY_ID'] = $case === 'wrong-delivery' ? 'wrong' : 'http-probe/id';
}
if ($case === 'bad-signature') { $_SERVER['HTTP_X_SIGNATURE_HMAC_SHA256'] = str_repeat('0', 64); }
$beforeMeta = $GLOBALS['order']->meta;
ob_start();
register_shutdown_function(static function () use ($beforeMeta) {
    $body = ob_get_clean();
    echo json_encode(array('httpStatus' => http_response_code(), 'body' => $body, 'lookups' => $GLOBALS['lookups'], 'status' => $GLOBALS['order']->status, 'unchangedMetadata' => $beforeMeta === $GLOBALS['order']->meta));
});
stream_wrapper_unregister('php');
stream_wrapper_register('php', HttpInput::class);
$gateway->webhook_response();
