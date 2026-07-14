<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

function add_action(): void
{
}

require dirname(__DIR__) . '/woocommerce-payment-gateway-app.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assertTrueValue(class_exists('WC_Payment_Gateway_App_Api_Error_Context'), 'The WooCommerce API error context helper must load.');

$providers = array();
for ($index = 0; $index < 25; $index++) {
    $providers[] = 'provider-' . $index;
}

$context = WC_Payment_Gateway_App_Api_Error_Context::parse(array(
    'error' => array(
        'code' => 'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD',
        'requestID' => "request-123\r\nInjected",
        'customerRiskHold' => array(
            'id' => 'hold-123',
            'action' => 'allow_provider_types',
            'reason' => "risk-review\nsecret@example.test",
        ),
        'allowedProviderTypes' => array_merge(array('wire', 'wise', 'wire'), $providers),
        'allowedProviderIds' => array('provider:one', str_repeat('x', 65), array('invalid')),
        'authorization' => 'Bearer must-not-log',
        'billingAddress' => array('email' => 'secret@example.test'),
        'message' => 'Raw backend message must not reach the customer or logs.',
    ),
), 409);

assertSameValue('CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD', $context['code'], 'The nested typed code must be parsed.');
assertSameValue('', $context['request_id'], 'Identifiers containing control characters must be rejected.');
assertSameValue('hold-123', $context['customer_risk_hold_id'], 'Nested hold IDs must be parsed.');
assertSameValue('allow_provider_types', $context['customer_risk_action'], 'Known hold actions must be parsed.');
assertSameValue(20, count($context['allowed_provider_types']), 'Provider lists must be deduplicated and capped at 20 values.');
assertSameValue(array('provider:one'), $context['allowed_provider_ids'], 'Oversized and non-scalar provider IDs must be discarded.');

$logContext = WC_Payment_Gateway_App_Api_Error_Context::log_context($context);
assertTrueValue(!array_key_exists('message', $logContext), 'Raw backend messages must never be logged.');
assertTrueValue(!array_key_exists('authorization', $logContext), 'Credentials must never be logged.');
assertTrueValue(!array_key_exists('billingAddress', $logContext), 'Billing data must never be logged.');

$customerMessage = WC_Payment_Gateway_App_Api_Error_Context::customer_message(
    $context,
    'Payment session creation failed due to an unexpected gateway response.'
);
assertTrueValue(str_contains($customerMessage, 'Only bank transfer payment methods are available'), 'Restricted holds need static customer guidance.');
assertTrueValue(!str_contains($customerMessage, 'Raw backend message'), 'Backend messages must not reach customers.');

$blocked = WC_Payment_Gateway_App_Api_Error_Context::parse(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD',
    'requestId' => 'request_456',
), 409);
assertSameValue(
    'Payment cannot be started because this customer account is under merchant review. Please contact support. Request ID: request_456',
    WC_Payment_Gateway_App_Api_Error_Context::customer_message($blocked, 'fallback'),
    'Blocked holds need a static message with a sanitized request ID.'
);

echo "WooCommerce API error contract: PASS\n";
