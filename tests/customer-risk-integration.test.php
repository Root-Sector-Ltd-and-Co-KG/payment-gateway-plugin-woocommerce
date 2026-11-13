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
    public string $phone = '';
    public array $statuses = array();
    public array $meta = array();
    public int $saves = 0;
    public function get_billing_email(): string { return 'buyer@example.test'; }
    public function get_billing_phone(): string { return $this->phone; }
    public function get_billing_first_name(): string { return 'Test'; }
    public function get_billing_last_name(): string { return 'Buyer'; }
    public function get_billing_address_1(): string { return 'Test street'; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_city(): string { return 'Berlin'; }
    public function get_billing_postcode(): string { return '10115'; }
    public function get_billing_country(): string { return 'DE'; }
    public function get_cancel_order_url(): string { return 'https://shop.example.test/cancel'; }
    public function get_total(): float { return 12.34; }
    public function get_id(): int { return 42; }
    public function update_status($status, $note = ''): void { $this->statuses[] = array($status, $note); }
    public function update_meta_data($key, $value): void { $this->meta[(string)$key] = $value; }
    public function save(): void { $this->saves++; }
}

class RiskTestWpdb
{
    public function prepare($query, ...$params): array { return array('query' => $query, 'params' => $params); }
    public function get_var($prepared): string
    {
        return str_contains((string)($prepared['query'] ?? ''), 'GET_LOCK') ? '1' : '1';
    }
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
function wp_remote_post($url, $args) { $GLOBALS['gateway_requests'][] = $args; return $GLOBALS['gateway_response']; }
function is_wp_error(): bool { return false; }
function wp_remote_retrieve_response_code($response): int { return (int)$response['response']['code']; }
function wp_remote_retrieve_body($response): string { return (string)$response['body']; }
function wc_add_notice($message, $type): void { $GLOBALS['gateway_notices'][] = array($type, $message); }
function wc_get_order() { return $GLOBALS['gateway_order']; }

$wpdb = new RiskTestWpdb();

require dirname(__DIR__) . '/woocommerce-payment-gateway-app.php';
init_woocommerce_payment_gateway_app();

function integrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function integrationRun(array $body, int $status, bool $debug, ?string $phone = null, bool $forwardBilling = true): array
{
    $GLOBALS['gateway_options'] = array(
        'api_domain' => 'api.example.test',
        'api_key' => 'secret-api-key',
        'site_id' => 'site-123',
        'debug' => $debug ? 'yes' : 'no',
        'pass_billing_address' => $phone !== null && $forwardBilling ? 'yes' : 'no',
    );
    $GLOBALS['gateway_response'] = array('response' => array('code' => $status), 'body' => json_encode($body));
    $GLOBALS['gateway_notices'] = array();
    $GLOBALS['gateway_requests'] = array();
    $GLOBALS['gateway_logger'] = new RiskTestLogger();
    $GLOBALS['gateway_order'] = new RiskTestOrder();
    $GLOBALS['gateway_order']->phone = $phone ?? '';
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

list($successResult, $successOrder) = integrationRun(array(
    'paymentUrl' => 'https://pay.example.test/session',
    'sessionPublicId' => 'session-public-attempt-b',
), 200, false);
integrationAssert($successResult === array('result' => 'success', 'redirect' => 'https://pay.example.test/session'), 'Normal successful checkout responses must remain unchanged.');
integrationAssert($successOrder->statuses === array(), 'Successful checkout must not cancel the order.');
integrationAssert(
    ($successOrder->meta[WC_Payment_Gateway_App_Checkout_Attempt::META_KEY] ?? null) === 'session-public-attempt-b',
    'The signed checkout-attempt identity must be persisted before redirecting.'
);
integrationAssert($successOrder->saves === 1, 'Persisting the checkout-attempt identity must durably save the order once.');

list($legacySuccessResult, $legacySuccessOrder) = integrationRun(array('paymentUrl' => 'https://pay.example.test/legacy'), 200, false);
integrationAssert($legacySuccessResult['result'] === 'success', 'A gateway response that omits the additive attempt identity must remain compatible.');
integrationAssert($legacySuccessOrder->meta === array(), 'An omitted attempt identity must not persist an ambiguous value.');

list($invalidAttemptResult, $invalidAttemptOrder, $invalidAttemptNotices) = integrationRun(array(
    'paymentUrl' => 'https://pay.example.test/invalid',
    'sessionPublicId' => 'invalid attempt identity',
), 200, false);
integrationAssert($invalidAttemptResult['result'] === 'failure', 'A malformed nonempty checkout-attempt identity must fail closed.');
integrationAssert($invalidAttemptOrder->meta === array(), 'A malformed checkout-attempt identity must never be persisted.');
integrationAssert(str_contains($invalidAttemptNotices[0][1], 'invalid gateway response'), 'Malformed attempt identity must expose only fixed safe guidance.');

foreach (array('030123456', '+49 30 123456', '+49(30)123456', '+49-30-123456', '004930123456', '+0123456789', '+1', '+1234567890123456', ' ', "+4930123456\n", ' +4930123456', '+４９３０１２３４５６', 'private-invalid-phone') as $phone) {
    foreach (array(false, true) as $debug) {
        list($result, $order, $notices, $logger) = integrationRun(array(
            'paymentUrl' => 'https://pay.example.test/session',
            'sessionPublicId' => 'session-new-attempt',
        ), 200, $debug, $phone);
        integrationAssert($result === array('result' => 'failure'), 'Invalid forwarded phone must stay on checkout for correction.');
        integrationAssert($GLOBALS['gateway_requests'] === array(), 'Invalid phone must not create a remote checkout.');
        integrationAssert($order->statuses === array() && $order->meta === array() && $order->saves === 0, 'Invalid phone must not cancel, save, or replace the order attempt.');
        integrationAssert(count($notices) === 1 && $notices[0][0] === 'error', 'Invalid phone needs one customer error notice.');
        foreach (array('billing phone', '+', 'country code', 'digits', 'spaces', 'brackets', '+4930123456') as $term) {
            integrationAssert(str_contains($notices[0][1], $term), 'Phone guidance must explain how to correct the number.');
        }
        integrationAssert(!str_contains(json_encode(array($notices, $logger->entries)), 'private-invalid-phone'), 'Invalid customer values must not be reflected in notices or logs.');
    }
}

foreach (array('', '+12', '+1234567', '+4930123456', '+123456789012345') as $phone) {
    list($result, $order, $notices) = integrationRun(array(
        'paymentUrl' => 'https://pay.example.test/session',
        'sessionPublicId' => 'session-valid-attempt',
    ), 200, false, $phone);
    integrationAssert($result['result'] === 'success' && $notices === array(), 'Backend-accepted phone syntax and empty optional phone must continue checkout.');
    integrationAssert(count($GLOBALS['gateway_requests']) === 1, 'Valid phone must create exactly one checkout.');
    $payload = json_decode($GLOBALS['gateway_requests'][0]['body'], true);
    integrationAssert($payload['billingAddress']['phone'] === $phone, 'Phone must be forwarded unchanged.');
    integrationAssert($order->meta[WC_Payment_Gateway_App_Checkout_Attempt::META_KEY] === 'session-valid-attempt' && $order->saves === 1, 'Valid checkout must preserve attempt persistence.');
}

list($result) = integrationRun(array('paymentUrl' => 'https://pay.example.test/session'), 200, false, 'invalid-not-forwarded', false);
integrationAssert($result['result'] === 'success' && count($GLOBALS['gateway_requests']) === 1, 'Non-forwarded phone must not block checkout.');
$payload = json_decode($GLOBALS['gateway_requests'][0]['body'], true);
integrationAssert(!isset($payload['billingAddress']), 'Disabled billing forwarding must remain disabled.');

list($result, $order, $notices, $logger) = integrationRun(array(
    'code' => 'VALIDATION_ERROR', 'message' => 'private-backend-detail', 'requestId' => 'phone-test-reference',
), 400, true, '+4930123456');
integrationAssert($result['result'] === 'failure', 'Other server validation errors must still fail.');
integrationAssert(str_contains($notices[0][1], 'unexpected error') && str_contains($notices[0][1], 'phone-test-reference'), 'Unknown server validation must retain safe fallback and support reference.');
integrationAssert(!str_contains(json_encode(array($notices, $logger->entries)), 'private-backend-detail'), 'Server details must remain private.');

echo "WooCommerce customer-risk and phone integration: PASS\n";
