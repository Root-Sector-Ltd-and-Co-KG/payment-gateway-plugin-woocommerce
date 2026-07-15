<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

require dirname(__DIR__) . '/includes/class-payment-gateway-app-api-error-context.php';

function riskAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function riskAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

riskAssertTrue(class_exists('Payment_Gateway_App_Api_Error_Context'), 'The pure API error context helper must load.');

$blocked = Payment_Gateway_App_Api_Error_Context::parse(json_encode(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD',
    'requestId' => 'request_123',
    'customerRiskHoldId' => 'hold-123',
    'message' => 'secret@example.test must never be shown',
)), 409);
riskAssertSame('CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD', $blocked['code'], 'Flat JSON envelopes must parse the blocked code.');
riskAssertSame('request_123', $blocked['request_id'], 'Flat JSON envelopes must parse requestId.');
riskAssertSame('hold-123', $blocked['customer_risk_hold_id'], 'Flat JSON envelopes must parse hold IDs.');
riskAssertSame(
    'Payment cannot be started because this customer account is under merchant review. Please contact support. Request ID: request_123',
    Payment_Gateway_App_Api_Error_Context::customer_message($blocked, 'fallback'),
    'Blocked errors must use static customer-safe copy and a sanitized request ID.'
);

$providers = array('wire', 'wise', 'wire');
for ($index = 0; $index < 25; $index++) {
    $providers[] = 'provider-' . $index;
}
$restricted = Payment_Gateway_App_Api_Error_Context::parse(array(
    'error' => array(
        'code' => 'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD',
        'requestID' => 'legacy.request-456',
        'customerRiskHold' => array(
            'id' => 'hold:456',
            'action' => 'allow_provider_types',
            'reason' => 'merchant_review',
        ),
        'allowedProviderTypes' => $providers,
        'allowedProviderIds' => array('provider:one', str_repeat('x', 65), array('nested')),
        'authorization' => 'Bearer must-not-log',
        'billingAddress' => array('email' => 'buyer@example.test'),
        'message' => 'Backend details must not reach customer or log.',
    ),
), 409);
riskAssertSame('CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD', $restricted['code'], 'Nested envelopes must parse the restricted code.');
riskAssertSame('legacy.request-456', $restricted['request_id'], 'Legacy requestID must be accepted when safe.');
riskAssertSame(20, count($restricted['allowed_provider_types']), 'Provider lists must be deduplicated and capped at 20 values.');
riskAssertSame(array('provider:one'), $restricted['allowed_provider_ids'], 'Non-scalar and oversized provider IDs must be discarded.');

$log = Payment_Gateway_App_Api_Error_Context::log_context($restricted, array(
    'source' => 'woocommerce-payment-gateway-app',
    'response_code' => 409,
    'raw_body' => 'buyer@example.test',
    'authorization' => 'Bearer must-not-log',
));
riskAssertSame('woocommerce-payment-gateway-app', $log['source'], 'Known static log context may be retained.');
riskAssertSame(409, $log['response_code'], 'HTTP response status may be retained.');
riskAssertTrue(!array_key_exists('raw_body', $log), 'Raw response bodies must never be logged.');
riskAssertTrue(!array_key_exists('authorization', $log), 'Credentials must never be logged.');
riskAssertTrue(!str_contains(json_encode($log), 'buyer@example.test'), 'PII must never be logged.');
riskAssertTrue(!str_contains(Payment_Gateway_App_Api_Error_Context::customer_message($restricted, 'fallback'), 'Backend details'), 'Backend messages must never reach customers.');

foreach (array(
    '{invalid-json',
    '"scalar"',
    json_encode(array('error' => array(array('code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD')))),
) as $invalidBody) {
    $invalid = Payment_Gateway_App_Api_Error_Context::parse($invalidBody, 500);
    riskAssertSame('', $invalid['code'], 'Invalid or non-object JSON must not create a typed context.');
}

$unsafe = Payment_Gateway_App_Api_Error_Context::parse(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD_EXTRA',
    'requestId' => "request-123\r\nInjected",
    'customerRiskHoldId' => str_repeat('h', 129),
), 409);
riskAssertSame('CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD_EXTRA', $unsafe['code'], 'Unknown codes may be retained only as bounded diagnostic identifiers.');
riskAssertSame('', $unsafe['request_id'], 'Identifiers containing control characters must be rejected.');
riskAssertSame('', $unsafe['customer_risk_hold_id'], 'Identifiers over 128 characters must be rejected.');
riskAssertSame('fallback', Payment_Gateway_App_Api_Error_Context::customer_message($unsafe, 'fallback'), 'Unknown codes must use the existing fallback.');

echo "WooCommerce customer-risk error context: PASS\n";
