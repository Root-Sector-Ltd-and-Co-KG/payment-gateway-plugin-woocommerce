<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

$GLOBALS['gateway_options'] = array();
$GLOBALS['gateway_response'] = array();
$GLOBALS['gateway_notices'] = array();
$GLOBALS['gateway_logger'] = null;
$GLOBALS['gateway_order'] = null;

class WC_Payment_Gateway
{
    public $id;
    public $method_title;
    public $has_fields;
    public $title;
    public $description;
    public $form_fields;

    public function init_settings(): void {}
    public function process_admin_options(): void {}
    public function get_option($key, $default = '') { return $GLOBALS['gateway_options'][$key] ?? $default; }
    public function get_return_url($order): string { return 'https://shop.example.test/return'; }
}

class RiskTestLogger
{
    public array $entries = array();
    public function debug($message, $context = array()): void { $this->entries[] = array('level' => 'debug', 'message' => $message, 'context' => $context); }
    public function error($message, $context = array()): void { $this->entries[] = array('level' => 'error', 'message' => $message, 'context' => $context); }
    public function info($message, $context = array()): void { $this->entries[] = array('level' => 'info', 'message' => $message, 'context' => $context); }
}

class RiskTestOrder
{
    public array $statuses = array();
    public function get_billing_email(): string { return 'buyer@example.test'; }
    public function get_cancel_order_url(): string { return 'https://shop.example.test/cancel'; }
    public function get_total(): float { return 12.34; }
    public function update_status($status, $note = ''): void { $this->statuses[] = array($status, $note); }
}

function add_action(): void {}
function add_filter(): void {}
function __($message): string { return $message; }
function get_woocommerce_currency(): string { return 'EUR'; }
function wc_get_logger(): RiskTestLogger { return $GLOBALS['gateway_logger']; }
function sanitize_email($email): string { return (string)$email; }
function sanitize_text_field($value): string { return trim((string)$value); }
function esc_url_raw($url): string { return (string)$url; }
function add_query_arg(): string { return 'https://shop.example.test/wc-api'; }
function home_url(): string { return 'https://shop.example.test/'; }
function wp_parse_url($url) { return parse_url((string)$url); }
function wp_remote_post() { return $GLOBALS['gateway_response']; }
function is_wp_error(): bool { return false; }
function wp_remote_retrieve_response_code($response): int { return (int)$response['response']['code']; }
function wp_remote_retrieve_body($response): string { return (string)$response['body']; }
function wc_add_notice($message, $type): void { $GLOBALS['gateway_notices'][] = array($type, $message); }
function wc_get_order() { return $GLOBALS['gateway_order']; }

require dirname(__DIR__) . '/woocommerce-payment-gateway-app.php';
init_woocommerce_payment_gateway_app();

function integrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function integrationRun(array $body, int $status, bool $debug): array
{
    $GLOBALS['gateway_options'] = array(
        'api_domain' => 'api.example.test',
        'api_key' => 'secret-api-key',
        'site_id' => 'site-123',
        'debug' => $debug ? 'yes' : 'no',
    );
    $GLOBALS['gateway_response'] = array('response' => array('code' => $status), 'body' => json_encode($body));
    $GLOBALS['gateway_notices'] = array();
    $GLOBALS['gateway_logger'] = new RiskTestLogger();
    $GLOBALS['gateway_order'] = new RiskTestOrder();
    $gateway = new WooCommerce_Payment_Gateway_App();
    $result = $gateway->process_payment(42);
    return array($result, $GLOBALS['gateway_order'], $GLOBALS['gateway_notices'], $GLOBALS['gateway_logger']);
}

integrationAssert(class_exists('Payment_Gateway_App_Api_Error_Context'), 'The plugin must load the extracted pure error helper.');

list($blockedResult, $blockedOrder, $blockedNotices, $blockedLogger) = integrationRun(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD',
    'requestId' => 'request-blocked',
    'message' => 'buyer@example.test must not be shown',
), 409, false);
integrationAssert($blockedResult['result'] === 'failure', 'Blocked customer holds must stop checkout.');
integrationAssert(count($blockedOrder->statuses) === 1 && $blockedOrder->statuses[0][0] === 'cancelled', 'Blocked checkout must not continue the order.');
integrationAssert(str_contains($blockedNotices[0][1], 'under merchant review'), 'Blocked checkout must show static safe guidance.');
integrationAssert(str_contains($blockedNotices[0][1], 'request-blocked'), 'Blocked checkout may show a sanitized request ID.');
integrationAssert(!str_contains($blockedNotices[0][1], 'buyer@example.test'), 'Blocked checkout must not show backend PII.');
integrationAssert($blockedLogger->entries === array(), 'Debug-off checkout failures must not emit logs.');

list($restrictedResult, $restrictedOrder, $restrictedNotices, $restrictedLogger) = integrationRun(array(
    'error' => array(
        'code' => 'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD',
        'requestID' => 'request-restricted',
        'holdId' => 'hold-123',
        'action' => 'allow_provider_types',
        'reason' => 'merchant_review',
        'allowedProviderTypes' => array('wire', 'wise'),
        'allowedProviderIds' => array('provider-1'),
        'message' => 'buyer@example.test and Bearer secret-api-key',
    ),
), 409, true);
integrationAssert($restrictedResult['result'] === 'failure', 'Restricted customer holds must stop the current checkout attempt.');
integrationAssert(str_contains($restrictedNotices[0][1], 'Only bank transfer payment methods'), 'Restricted checkout must show static safe guidance.');
$encodedLogs = json_encode($restrictedLogger->entries);
integrationAssert(str_contains($encodedLogs, 'allowed_provider_types'), 'Debug-on logging must retain allowlisted provider context.');
integrationAssert(!str_contains($encodedLogs, 'buyer@example.test'), 'Debug logs must exclude PII and backend messages.');
integrationAssert(!str_contains($encodedLogs, 'secret-api-key'), 'Debug logs must exclude credentials and raw request data.');

list($successResult, $successOrder) = integrationRun(array('paymentUrl' => 'https://pay.example.test/session'), 200, false);
integrationAssert($successResult === array('result' => 'success', 'redirect' => 'https://pay.example.test/session'), 'Normal successful checkout responses must remain unchanged.');
integrationAssert($successOrder->statuses === array(), 'Successful checkout must not cancel the order.');

echo "WooCommerce customer-risk integration: PASS\n";
