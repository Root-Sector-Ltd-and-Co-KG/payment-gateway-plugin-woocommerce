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
    public function get_currency(): string { return 'EUR'; }
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

// Exercise the actual checkout payload, including a saved order currency that
// differs from the shop's current EUR setting. No network is used by the stub.
class AmountTestItem
{
    public function __construct(public string $net, public string $tax = '0', public int $quantity = 1, public bool $virtual = false, public string $name = 'Product') {}
    public function get_total() { return $this->net; }
    public function get_total_tax() { return $this->tax; }
    public function get_quantity() { return $this->quantity; }
    public function get_product() { return $this; }
    public function is_virtual() { return $this->virtual; }
    public function get_name() { return $this->name; }
}
class AmountTestOrder extends RiskTestOrder
{
    public string $currency = 'EUR';
    public array $items = array();
    public array $fees = array();
    public string $shipping = '0';
    public string $shippingTax = '0';
    public string $tax = '0';
    public float $total = 10.0;
    public function get_total(): float { return $this->total; }
    public function get_currency(): string { return $this->currency; }
    public function get_items() { return $this->items; }
    public function get_fees() { return $this->fees; }
    public function get_shipping_total() { return $this->shipping; }
    public function get_shipping_tax() { return $this->shippingTax; }
    public function get_total_tax() { return $this->tax; }
}
function amountCheckout(AmountTestOrder $order, string $mode = 'included', bool $passItems = true): array
{
    $GLOBALS['gateway_options'] = array('api_domain' => 'api.example.test', 'api_key' => 'test-only', 'site_id' => 'site', 'pass_items' => $passItems ? 'yes' : 'no', 'tax_handling' => $mode);
    $GLOBALS['gateway_order'] = $order;
    $GLOBALS['gateway_requests'] = array();
    $GLOBALS['gateway_notices'] = array();
    $GLOBALS['gateway_response'] = array('response' => array('code' => 200), 'body' => '{"paymentUrl":"https://pay.example.test/session"}');
    $result = (new WooCommerce_Payment_Gateway_App())->process_payment(42);
    return array($result, isset($GLOBALS['gateway_requests'][0]) ? json_decode($GLOBALS['gateway_requests'][0]['body'], true) : null);
}
foreach (array(array('JPY', 1000, '1000', 1000), array('EUR', 10, '10.00', 1000), array('KWD', 1.234, '1.234', 1234)) as [$currency, $major, $line, $minor]) {
    $order = new AmountTestOrder();
    $order->currency = $currency;
    $order->total = $major;
    $order->items = array(new AmountTestItem($line, '0', 3));
    [$result, $payload] = amountCheckout($order);
    integrationAssert($result['result'] === 'success' && $payload['amount'] === $minor && $payload['currency'] === $currency, 'Use saved order currency and its minor units: ' . $currency);
    integrationAssert(array_sum(array_column($payload['items'], 'quantity')) === 3, 'Split prices must preserve original quantity.');
    $sum = 0;
    foreach ($payload['items'] as $item) { $sum += $item['quantity'] * $item['unitPrice']; integrationAssert($item['itemType'] === 'goods', 'Product type must be preserved.'); }
    integrationAssert($sum === $minor, 'Split item amounts must equal declared total.');
    if ($currency === 'EUR') { integrationAssert($payload['items'][0]['unitPrice'] === 333 && $payload['items'][0]['quantity'] === 2 && $payload['items'][1]['unitPrice'] === 334, 'EUR 10 / 3 must allocate exactly two 333 and one 334 unit.'); }
}
foreach (array('included', 'separate') as $mode) {
    $order = new AmountTestOrder();
    $order->total = 12.5;
    $order->items = array(new AmountTestItem('8.40', '1.60', 3, true));
    $order->shipping = '2.00'; $order->shippingTax = '0.40'; $order->tax = '2.00';
    $order->fees = array(new AmountTestItem('0.60', '0', 1, true, 'Fee'), new AmountTestItem('-0.50', '0', 1, true, 'Discount'));
    [$result, $payload] = amountCheckout($order, $mode);
    integrationAssert($result['result'] === 'success' && $payload['amount'] === 1250, 'Tax modes must retain final order amount.');
    $totals = array(); foreach ($payload['items'] as $item) { $totals[$item['itemType']] = ($totals[$item['itemType']] ?? 0) + $item['quantity'] * $item['unitPrice']; }
    integrationAssert(array_sum($totals) === 1250 && $totals['discount'] === -50 && $totals['shipping'] === ($mode === 'included' ? 240 : 200), 'Fees, discounts and shipping must retain their meanings.');
    integrationAssert(($totals['tax'] ?? 0) === ($mode === 'included' ? 0 : 200), 'Separate tax must remain exact and included tax must not be duplicated.');
}
$order = new AmountTestOrder(); $order->total = 0.01; $order->items = array(new AmountTestItem('0.01', '0', 3));
[$result, $payload] = amountCheckout($order);
integrationAssert($result['result'] === 'failure' && $payload === null && count($GLOBALS['gateway_notices']) === 1, 'Unrepresentable positive unit prices must fail visibly before sending.');
[$result, $payload] = amountCheckout($order, 'included', false);
integrationAssert($result['result'] === 'success' && $payload['amount'] === 1 && !isset($payload['items']), 'Merchant can explicitly disable item forwarding without changing the order amount.');
$order->total = 10; $order->items = array(new AmountTestItem('9.00'));
[$result, $payload] = amountCheckout($order);
integrationAssert($result['result'] === 'failure' && $payload === null, 'A genuine line/order mismatch must not alter a product or tax amount.');
echo "WooCommerce checkout amount contract: PASS\n";

foreach (array(
    array('JPY', '1000', 1000), array('EUR', '0.29', 29), array('KWD', '1.234', 1234),
    array('CLF', '1.2345', 12345), array('eur', '1.005', 101), array('EUR', '-1.005', -101),
    array('EUR', '90071992547409.91', 9007199254740991), array('EUR', '92233720368547758.07', PHP_INT_MAX),
) as [$currency, $value, $expected]) {
    integrationAssert($expected === WC_Payment_Gateway_App_Checkout_Amounts::minorUnits($value, $currency), 'Exact decimal conversion: ' . $value);
}
integrationAssert(1 === WC_Payment_Gateway_App_Checkout_Amounts::sumMinorUnits(array('0.004', '0.004'), 'EUR'), 'Gross is rounded only after exact summation.');
integrationAssert(-125 === WC_Payment_Gateway_App_Checkout_Amounts::sumMinorUnits(array('0.25', '-1.50'), 'EUR'), 'Mixed-sign summation must preserve discounts.');
foreach (array('1e3', 'NaN', '1,00', '', '92233720368547758.08') as $invalid) {
    $rejected = false;
    try { WC_Payment_Gateway_App_Checkout_Amounts::minorUnits($invalid, 'EUR'); } catch (InvalidArgumentException $error) { $rejected = true; }
    integrationAssert($rejected, 'Invalid or overflowing amount must be rejected.');
}

$oldPrecision = ini_get('precision'); $oldSerializePrecision = ini_get('serialize_precision');
try {
    foreach (array(array('14', '14'), array('6', '3'), array('17', '-1')) as [$precision, $serializePrecision]) {
        ini_set('precision', $precision); ini_set('serialize_precision', $serializePrecision);
        foreach (array(array(123456789012.345, 'KWD', 123456789012345), array(1.005, 'EUR', 101), array(-1.005, 'EUR', -101), array(1.0e-7, 'EUR', 0), array(1.0e12, 'JPY', 1000000000000)) as [$value, $currency, $expected]) {
            integrationAssert($expected === WC_Payment_Gateway_App_Checkout_Amounts::minorUnits($value, $currency), 'Legacy floats must not depend on ini precision.');
        }
        $order = new AmountTestOrder(); $order->currency = 'KWD'; $order->total = 123456789012.345;
        [$result, $payload] = amountCheckout($order, 'included', false);
        integrationAssert($result['result'] === 'success' && $payload['amount'] === 123456789012345 && !isset($payload['items']), 'Production checkout must preserve legacy float without item forwarding.');
    }
} finally {
    ini_set('precision', $oldPrecision); ini_set('serialize_precision', $oldSerializePrecision);
}
foreach (array(INF, -INF, NAN, 1.0e20, 9007199254740992.0, '1e3') as $invalid) {
    $rejected = false;
    try { WC_Payment_Gateway_App_Checkout_Amounts::minorUnits($invalid, 'JPY'); } catch (InvalidArgumentException $error) { $rejected = true; }
    integrationAssert($rejected, 'Non-finite, ambiguous or exponent-string values must fail safely.');
}

foreach (array(array('1.004', '2.004'), array('1.006', '2.006')) as [$first, $second]) {
    $order = new AmountTestOrder(); $order->total = 3.01;
    $order->items = array(new AmountTestItem($first, '0', 1, false, 'First'), new AmountTestItem($second, '0', 2, true, 'Second'));
    [$result, $payload] = amountCheckout($order);
    integrationAssert($result['result'] === 'success', 'Only aggregate rounding drift must be reconciled.');
    $sum = 0; foreach ($payload['items'] as $item) { $sum += $item['quantity'] * $item['unitPrice']; }
    integrationAssert($sum === 301 && array_sum(array_column($payload['items'], 'quantity')) === 3, 'Reconciliation must preserve quantity and declared amount.');
}

$roundedItems = WC_Payment_Gateway_App_Checkout_Amounts::buildItems(array(
    array('description' => 'Small', 'quantity' => 1, 'values' => array('0.005'), 'itemType' => 'goods'),
    array('description' => 'Adjustable', 'quantity' => 1, 'values' => array('1.005'), 'itemType' => 'goods'),
    array('description' => 'Exact tax', 'quantity' => 1, 'values' => array('0.20'), 'itemType' => 'tax'),
), 121, 'EUR');
integrationAssert(array(1, 100, 20) === array_column($roundedItems, 'unitPrice'), 'Reconciliation must skip zero-price alternatives and preserve exact tax.');

// Near 9e12 KWD, binary64 spacing exceeds 0.001 KWD; reject the
// legacy float rather than pretending it identifies an adjacent minor unit.
$ambiguousKwdRejected = false;
try { WC_Payment_Gateway_App_Checkout_Amounts::minorUnits(9000000000000.001, 'KWD'); }
catch (InvalidArgumentException $error) { $ambiguousKwdRejected = true; }
integrationAssert($ambiguousKwdRejected, 'Adjacent KWD minor units must remain distinguishable for legacy floats.');
integrationAssert(9000000000000001 === WC_Payment_Gateway_App_Checkout_Amounts::minorUnits('9000000000000.001', 'KWD'), 'Canonical decimal strings must retain full minor-unit precision.');

foreach (array(array('1.004', '2.004', 3.00, array(100, 200)), array('0.004', '1.006', 1.01, array(1, 100))) as [$first, $second, $total, $prices]) {
    $order = new AmountTestOrder(); $order->total = $total;
    $order->items = array(new AmountTestItem($first, '0', 1, false, 'First'), new AmountTestItem($second, '0', 1, true, 'Second'));
    [$result, $payload] = amountCheckout($order);
    integrationAssert($result['result'] === 'success', 'Feasible line rounding must create a checkout.');
    integrationAssert($prices === array_column($payload['items'], 'unitPrice'), 'Preserve balanced lines or choose valid floor/ceil prices.');
    integrationAssert(array(1, 1) === array_column($payload['items'], 'quantity'), 'Do not invent items or change quantities.');
}
$order = new AmountTestOrder(); $order->total = 1.01;
$order->items = array(new AmountTestItem('0'), new AmountTestItem('1.01'));
[$result, $payload] = amountCheckout($order);
integrationAssert($result['result'] === 'failure' && $payload === null, 'A true zero line has no valid positive floor/ceil and must still fail.');
