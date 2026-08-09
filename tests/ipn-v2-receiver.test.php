<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

eval('namespace Automattic\\WooCommerce\\Utilities; final class FeaturesUtil { public static array $declarations = array(); public static function declare_compatibility($feature, $file, $compatible): void { self::$declarations[] = array($feature, $file, $compatible); } }');

$ipnRegisteredActions = array();

function add_action($hook, $callback = null): void
{
    global $ipnRegisteredActions;
    $ipnRegisteredActions[$hook][] = $callback;
}

require dirname(__DIR__) . '/woocommerce-payment-gateway-app.php';

function ipnAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function ipnAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function signedHeaders(string $body, string $secret, int $timestamp, ?string $version = null, ?string $deliveryId = null): array
{
    $headers = array(
        'timestamp' => (string) $timestamp,
        'signature' => hash_hmac('sha256', $timestamp . '.' . $body, $secret),
    );
    if ($version !== null) {
        $headers['version'] = $version;
    }
    if ($deliveryId !== null) {
        $headers['delivery_id'] = $deliveryId;
    }
    return $headers;
}

function v2Payload(string $deliveryId, int $eventVersion, $status = 1): array
{
    return array(
        'schemaVersion' => 2,
        'deliveryId' => $deliveryId,
        'eventVersion' => $eventVersion,
        'occurredAt' => '2026-07-26T18:30:00Z',
        'id' => 'transaction-123',
        'externalReference' => '42',
        'status' => $status,
    );
}

final class FakeIPNOrder
{
    public array $trace = array();
    public string $status = 'pending';
    public string $transactionId = '';
    private array $meta = array();

    public function get_meta(string $key, bool $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): void
    {
        $this->trace[] = 'save';
    }

    public function is_paid(): bool
    {
        return in_array($this->status, array('processing', 'completed'), true);
    }

    public function get_transaction_id(): string
    {
        return $this->transactionId;
    }

    public function set_transaction_id(string $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->trace[] = 'bind:' . $transactionId;
    }

    public function payment_complete(string $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->status = 'processing';
        $this->trace[] = 'payment:' . $transactionId;
    }

    public function update_status(string $status): void
    {
        $this->status = $status;
        $this->trace[] = 'status:' . $status;
    }
}

$secret = 'whsec_test_receiver_secret';
$now = 1785088800;

foreach ($ipnRegisteredActions['before_woocommerce_init'] ?? array() as $callback) {
    $callback();
}
ipnAssertSame(
    array(array('custom_order_tables', dirname(__DIR__) . '/woocommerce-payment-gateway-app.php', true)),
    \Automattic\WooCommerce\Utilities\FeaturesUtil::$declarations,
    'The plugin must declare HPOS compatibility through WooCommerce FeaturesUtil.'
);

// A missing version header remains the bounded v1 compatibility path.
$v1Body = json_encode(array('id' => 'transaction-123', 'externalReference' => '42', 'status' => 1), JSON_THROW_ON_ERROR);
$v1 = WC_Payment_Gateway_App_IPN_Request::verify($v1Body, signedHeaders($v1Body, $secret, $now), $secret, $now);
ipnAssertSame(true, $v1['ok'], 'A correctly signed legacy IPN must remain accepted during the migration window.');
ipnAssertSame(1, $v1['version'], 'A payload without version markers must use the legacy v1 path.');

$deliveryId = 'delivery-123';
$payload = v2Payload($deliveryId, 1);
$body = json_encode($payload, JSON_THROW_ON_ERROR);
$verified = WC_Payment_Gateway_App_IPN_Request::verify($body, signedHeaders($body, $secret, $now, '2', $deliveryId), $secret, $now);
ipnAssertSame(true, $verified['ok'], 'A correctly signed and versioned v2 IPN must be accepted.');
ipnAssertSame(2, $verified['version'], 'The verified request must retain the negotiated IPN version.');

$nonCanonicalCases = array(
    'alias_only_transaction' => (function (): array {
        $candidate = v2Payload('delivery-alias-transaction', 2);
        unset($candidate['id']);
        $candidate['transactionId'] = 'transaction-123';
        return $candidate;
    })(),
    'alias_only_external_reference' => (function (): array {
        $candidate = v2Payload('delivery-alias-reference', 3);
        unset($candidate['externalReference']);
        $candidate['chargeback'] = array('externalReference' => '42');
        return $candidate;
    })(),
    'hybrid_dispute_status' => (function (): array {
        $candidate = v2Payload('delivery-hybrid', 4);
        $candidate['status'] = 1;
        $candidate['disputeStatus'] = 'lost';
        return $candidate;
    })(),
    'typed_transaction' => (function (): array {
        $candidate = v2Payload('delivery-typed-transaction', 5);
        $candidate['id'] = 123;
        return $candidate;
    })(),
    'typed_external_reference' => (function (): array {
        $candidate = v2Payload('delivery-typed-reference', 6);
        $candidate['externalReference'] = 42;
        return $candidate;
    })(),
);
$nonCanonicalResults = array();
foreach ($nonCanonicalCases as $case => $candidate) {
    $candidateBody = json_encode($candidate, JSON_THROW_ON_ERROR);
    $result = WC_Payment_Gateway_App_IPN_Request::verify(
        $candidateBody,
        signedHeaders($candidateBody, $secret, $now, '2', $candidate['deliveryId']),
        $secret,
        $now
    );
    $nonCanonicalResults[$case] = array($result['ok'], $result['code'] ?? null);
}
ipnAssertSame(
    array(
        'alias_only_transaction' => array(false, 'legacy_alias_not_allowed'),
        'alias_only_external_reference' => array(false, 'legacy_alias_not_allowed'),
        'hybrid_dispute_status' => array(false, 'legacy_alias_not_allowed'),
        'typed_transaction' => array(false, 'invalid_transaction_id'),
        'typed_external_reference' => array(false, 'invalid_external_reference'),
    ),
    $nonCanonicalResults,
    'IPN v2 must require canonical typed identity fields and reject legacy alias or hybrid payloads.'
);

$mismatched = WC_Payment_Gateway_App_IPN_Request::verify($body, signedHeaders($body, $secret, $now, '2', 'different-delivery'), $secret, $now);
ipnAssertSame(false, $mismatched['ok'], 'A v2 delivery header/body mismatch must be rejected.');
ipnAssertSame('delivery_id_mismatch', $mismatched['code'], 'A v2 delivery mismatch needs a stable non-sensitive error code.');

$missingVersionHeader = WC_Payment_Gateway_App_IPN_Request::verify($body, signedHeaders($body, $secret, $now), $secret, $now);
ipnAssertSame(false, $missingVersionHeader['ok'], 'A schema-v2 body must not silently downgrade to the v1 path.');
ipnAssertSame('version_mismatch', $missingVersionHeader['code'], 'Missing v2 negotiation must be distinguishable from malformed JSON.');

$invalidEvent = v2Payload('delivery-invalid', 0);
$invalidEventBody = json_encode($invalidEvent, JSON_THROW_ON_ERROR);
$invalidEventResult = WC_Payment_Gateway_App_IPN_Request::verify($invalidEventBody, signedHeaders($invalidEventBody, $secret, $now, '2', 'delivery-invalid'), $secret, $now);
ipnAssertSame(false, $invalidEventResult['ok'], 'A non-positive event version must be rejected.');

$invalidDate = v2Payload('delivery-date', 2);
$invalidDate['occurredAt'] = '2026-02-30T18:30:00Z';
$invalidDateBody = json_encode($invalidDate, JSON_THROW_ON_ERROR);
$invalidDateResult = WC_Payment_Gateway_App_IPN_Request::verify($invalidDateBody, signedHeaders($invalidDateBody, $secret, $now, '2', 'delivery-date'), $secret, $now);
ipnAssertSame(false, $invalidDateResult['ok'], 'An impossible occurredAt date must be rejected.');

// Invalid payment statuses must be rejected before they can reserve durable event state.
$statusIntegrityOrder = new FakeIPNOrder();
$statusIntegrityEffects = 0;
$invalidStatusPayloads = array(
    'fractional' => v2Payload('delivery-status-fractional', 10, 1.9),
    'numeric_string' => v2Payload('delivery-status-numeric-string', 11, '1'),
    'unsupported_integer' => v2Payload('delivery-status-unsupported', 12, 5),
    'missing' => v2Payload('delivery-status-missing', 13),
);
unset($invalidStatusPayloads['missing']['status']);

$statusRejections = array();
foreach ($invalidStatusPayloads as $case => $invalidStatusPayload) {
    $invalidStatusBody = json_encode($invalidStatusPayload, JSON_THROW_ON_ERROR);
    $invalidStatusResult = WC_Payment_Gateway_App_IPN_Request::verify(
        $invalidStatusBody,
        signedHeaders($invalidStatusBody, $secret, $now, '2', $invalidStatusPayload['deliveryId']),
        $secret,
        $now
    );
    $statusRejections[$case] = array($invalidStatusResult['ok'], $invalidStatusResult['code'] ?? null);
    if ($invalidStatusResult['ok']) {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $statusIntegrityOrder,
            'transaction-status-integrity',
            $invalidStatusResult['payload'],
            $invalidStatusBody,
            function () use (&$statusIntegrityEffects): void {
                $statusIntegrityEffects++;
            }
        );
    }
}

$validAfterInvalidPayload = v2Payload('delivery-status-valid', 1, 1);
$validAfterInvalidBody = json_encode($validAfterInvalidPayload, JSON_THROW_ON_ERROR);
$validAfterInvalidVerification = WC_Payment_Gateway_App_IPN_Request::verify(
    $validAfterInvalidBody,
    signedHeaders($validAfterInvalidBody, $secret, $now, '2', 'delivery-status-valid'),
    $secret,
    $now
);
$validAfterInvalidResult = $validAfterInvalidVerification['ok']
    ? WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $statusIntegrityOrder,
        'transaction-status-integrity',
        $validAfterInvalidVerification['payload'],
        $validAfterInvalidBody,
        static function (): void {
        }
    )
    : 'verification_failed';
$statusIntegrityState = $statusIntegrityOrder->get_meta(
    WC_Payment_Gateway_App_IPN_V2_Processor::meta_key('transaction-status-integrity'),
    true
);

ipnAssertSame(
    array(
        'rejections' => array(
            'fractional' => array(false, 'invalid_status'),
            'numeric_string' => array(false, 'invalid_status'),
            'unsupported_integer' => array(false, 'invalid_status'),
            'missing' => array(false, 'invalid_status'),
        ),
        'invalidEffects' => 0,
        'validAfterResult' => 'applied',
        'highestEventVersion' => 1,
        'trace' => array('save', 'save'),
    ),
    array(
        'rejections' => $statusRejections,
        'invalidEffects' => $statusIntegrityEffects,
        'validAfterResult' => $validAfterInvalidResult,
        'highestEventVersion' => $statusIntegrityState['highestEventVersion'] ?? null,
        'trace' => $statusIntegrityOrder->trace,
    ),
    'Invalid v2 payment statuses must not mutate effects, deduplication, or highest event version.'
);

// If the first acknowledgement is lost, a newly signed resend must not repeat effects.
$order = new FakeIPNOrder();
$effects = 0;
$first = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $order,
    'transaction-123',
    $verified['payload'],
    $body,
    function (FakeIPNOrder $effectOrder) use (&$effects): void {
        $effectOrder->trace[] = 'effect';
        $effects++;
    }
);
ipnAssertSame('applied', $first, 'The first delivery must apply its WooCommerce effect.');
ipnAssertSame(array('save', 'effect', 'save'), $order->trace, 'The delivery marker must be saved before the non-idempotent WooCommerce effect.');

$freshVerification = WC_Payment_Gateway_App_IPN_Request::verify($body, signedHeaders($body, $secret, $now + 1, '2', $deliveryId), $secret, $now + 1);
ipnAssertSame(true, $freshVerification['ok'], 'The same body may be retried with a fresh signature timestamp.');
$duplicate = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $order,
    'transaction-123',
    $freshVerification['payload'],
    $body,
    function () use (&$effects): void {
        $effects++;
    }
);
ipnAssertSame('duplicate', $duplicate, 'An already applied delivery must be acknowledged as a duplicate.');
ipnAssertSame(1, $effects, 'A duplicate resend must not repeat WooCommerce effects.');

// A later event wins even if an older delivery arrives afterwards.
$orderedOrder = new FakeIPNOrder();
$appliedStatuses = array();
$newerPayload = v2Payload('delivery-newer', 2, 1);
$newerBody = json_encode($newerPayload, JSON_THROW_ON_ERROR);
$newerResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $orderedOrder,
    'transaction-123',
    $newerPayload,
    $newerBody,
    function (FakeIPNOrder $unused, array $event) use (&$appliedStatuses): void {
        $appliedStatuses[] = $event['status'];
    }
);
ipnAssertSame('applied', $newerResult, 'The newest transaction event must be applied.');

$olderPayload = v2Payload('delivery-older', 1, 2);
$olderBody = json_encode($olderPayload, JSON_THROW_ON_ERROR);
$olderResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $orderedOrder,
    'transaction-123',
    $olderPayload,
    $olderBody,
    function (FakeIPNOrder $unused, array $event) use (&$appliedStatuses): void {
        $appliedStatuses[] = $event['status'];
    }
);
ipnAssertSame('outdated', $olderResult, 'An out-of-order event must be acknowledged without regressing the order.');
ipnAssertSame(array(1), $appliedStatuses, 'The stale failed status must not run its WooCommerce effect.');

// Order effects are bound to the payment attempt that actually paid the order.
$attemptOrder = new FakeIPNOrder();
$paymentB = v2Payload('delivery-attempt-b-payment', 1, 1);
$paymentB['id'] = 'transaction-b';
$paymentBBody = json_encode($paymentB, JSON_THROW_ON_ERROR);
$paymentBResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $attemptOrder,
    'transaction-b',
    $paymentB,
    $paymentBBody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-b');
    }
);
$lateFailureA = v2Payload('delivery-attempt-a-failed', 3, 2);
$lateFailureA['id'] = 'transaction-a';
$lateFailureABody = json_encode($lateFailureA, JSON_THROW_ON_ERROR);
$lateFailureAResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $attemptOrder,
    'transaction-a',
    $lateFailureA,
    $lateFailureABody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('failed');
    }
);
$lateFailureADuplicate = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $attemptOrder,
    'transaction-a',
    $lateFailureA,
    $lateFailureABody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('failed');
    }
);
ipnAssertSame('applied', $paymentBResult, 'The successful checkout attempt must be applied.');
ipnAssertSame('applied', $lateFailureAResult, 'A stale attempt event must be durably acknowledged without mutating the order.');
ipnAssertSame('duplicate', $lateFailureADuplicate, 'An acknowledgement-loss retry for a stale attempt must be duplicate-safe.');
ipnAssertSame('processing', $attemptOrder->status, 'A late failed event from transaction A must not regress an order paid by transaction B.');
ipnAssertSame('transaction-b', $attemptOrder->transactionId, 'The paid transaction must remain the active payment attempt.');

$refundB = v2Payload('delivery-attempt-b-refund', 2, 3);
$refundB['id'] = 'transaction-b';
$refundBBody = json_encode($refundB, JSON_THROW_ON_ERROR);
WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $attemptOrder,
    'transaction-b',
    $refundB,
    $refundBBody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('refunded');
    }
);
$lateCancelA = v2Payload('delivery-attempt-a-cancelled', 4, -2);
$lateCancelA['id'] = 'transaction-a';
$lateCancelABody = json_encode($lateCancelA, JSON_THROW_ON_ERROR);
$lateCancelAResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $attemptOrder,
    'transaction-a',
    $lateCancelA,
    $lateCancelABody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('cancelled');
    }
);
ipnAssertSame('applied', $lateCancelAResult, 'A stale attempt remains acknowledgeable after the active attempt is refunded.');
ipnAssertSame('refunded', $attemptOrder->status, 'The active payment transaction binding must survive terminal order status changes.');
ipnAssertSame('transaction-b', $attemptOrder->transactionId, 'Terminal order status changes must retain the active payment-attempt identity.');

$lateSuccessA = v2Payload('delivery-attempt-a-success', 5, 1);
$lateSuccessA['id'] = 'transaction-a';
$lateSuccessABody = json_encode($lateSuccessA, JSON_THROW_ON_ERROR);
$lateSuccessAResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $attemptOrder,
    'transaction-a',
    $lateSuccessA,
    $lateSuccessABody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-a');
    }
);
ipnAssertSame('applied', $lateSuccessAResult, 'A delayed success for a stale attempt must be durably acknowledged.');
ipnAssertSame('refunded', $attemptOrder->status, 'A delayed transaction-A success must not reopen a transaction-B-refunded order.');
ipnAssertSame('transaction-b', $attemptOrder->transactionId, 'A delayed transaction-A success must not rebind the refunded order.');

// The same active-attempt invariant applies across the retained v1 and canonical v2 paths.
$mixedOrder = new FakeIPNOrder();
$legacyPayment = WC_Payment_Gateway_App_IPN_Order_Attempt::apply(
    $mixedOrder,
    'transaction-v1-b',
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-v1-b');
    }
);
$mixedV2Cancel = v2Payload('delivery-mixed-v2-cancel', 1, -2);
$mixedV2Cancel['id'] = 'transaction-v2-a';
$mixedV2CancelBody = json_encode($mixedV2Cancel, JSON_THROW_ON_ERROR);
$mixedV2CancelResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $mixedOrder,
    'transaction-v2-a',
    $mixedV2Cancel,
    $mixedV2CancelBody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('cancelled');
    }
);
$lateLegacyFailure = WC_Payment_Gateway_App_IPN_Order_Attempt::apply(
    $attemptOrder,
    'transaction-v1-a',
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('failed');
    }
);
ipnAssertSame('applied', $legacyPayment, 'A signed v1 payment effect may establish the active paid transaction.');
ipnAssertSame('applied', $mixedV2CancelResult, 'A canonical v2 stale-attempt event must still be acknowledged.');
ipnAssertSame('stale_attempt', $lateLegacyFailure, 'A signed v1 stale-attempt effect must be suppressed after v2 payment.');
ipnAssertSame('processing', $mixedOrder->status, 'A v2 cancellation from another transaction must not regress a v1-paid order.');
ipnAssertSame('transaction-v1-b', $mixedOrder->transactionId, 'Mixed-protocol handling must preserve the active paid transaction.');
ipnAssertSame('refunded', $attemptOrder->status, 'A v1 failure from another transaction must not regress the v2 payment attempt.');

$v1PaidV2LateSuccessOrder = new FakeIPNOrder();
WC_Payment_Gateway_App_IPN_Order_Attempt::apply(
    $v1PaidV2LateSuccessOrder,
    'transaction-v1-b',
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-v1-b');
    }
);
WC_Payment_Gateway_App_IPN_Order_Attempt::apply(
    $v1PaidV2LateSuccessOrder,
    'transaction-v1-b',
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('refunded');
    }
);
$v2LateSuccess = v2Payload('delivery-mixed-v2-late-success', 1, 1);
$v2LateSuccess['id'] = 'transaction-v2-a';
$v2LateSuccessBody = json_encode($v2LateSuccess, JSON_THROW_ON_ERROR);
WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $v1PaidV2LateSuccessOrder,
    'transaction-v2-a',
    $v2LateSuccess,
    $v2LateSuccessBody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-v2-a');
    }
);
ipnAssertSame('refunded', $v1PaidV2LateSuccessOrder->status, 'A v2 delayed success must not reopen an order refunded by its v1 payment attempt.');
ipnAssertSame('transaction-v1-b', $v1PaidV2LateSuccessOrder->transactionId, 'A v2 delayed success must not replace the retained v1 payment identity.');

$v2PaidV1LateSuccessOrder = new FakeIPNOrder();
$v2Payment = v2Payload('delivery-mixed-v2-payment', 1, 1);
$v2Payment['id'] = 'transaction-v2-b';
$v2PaymentBody = json_encode($v2Payment, JSON_THROW_ON_ERROR);
WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $v2PaidV1LateSuccessOrder,
    'transaction-v2-b',
    $v2Payment,
    $v2PaymentBody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-v2-b');
    }
);
$v2Refund = v2Payload('delivery-mixed-v2-refund', 2, 3);
$v2Refund['id'] = 'transaction-v2-b';
$v2RefundBody = json_encode($v2Refund, JSON_THROW_ON_ERROR);
WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $v2PaidV1LateSuccessOrder,
    'transaction-v2-b',
    $v2Refund,
    $v2RefundBody,
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->update_status('refunded');
    }
);
$v1LateSuccessResult = WC_Payment_Gateway_App_IPN_Order_Attempt::apply(
    $v2PaidV1LateSuccessOrder,
    'transaction-v1-a',
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-v1-a');
    }
);
ipnAssertSame('stale_attempt', $v1LateSuccessResult, 'A delayed v1 success for another transaction must be suppressed after a v2 refund.');
ipnAssertSame('refunded', $v2PaidV1LateSuccessOrder->status, 'A v1 delayed success must not reopen an order refunded by its v2 payment attempt.');
ipnAssertSame('transaction-v2-b', $v2PaidV1LateSuccessOrder->transactionId, 'A v1 delayed success must not replace the retained v2 payment identity.');

$explicitReplacementOrder = new FakeIPNOrder();
$explicitReplacementOrder->payment_complete('transaction-old');
$explicitReplacementOrder->update_status('refunded');
$explicitReplacementOrder->set_transaction_id('transaction-replacement');
$explicitReplacementResult = WC_Payment_Gateway_App_IPN_Order_Attempt::apply(
    $explicitReplacementOrder,
    'transaction-replacement',
    static function (FakeIPNOrder $effectOrder): void {
        $effectOrder->payment_complete('transaction-replacement');
    }
);
ipnAssertSame('applied', $explicitReplacementResult, 'An explicitly rebound replacement payment attempt must remain processable.');
ipnAssertSame('processing', $explicitReplacementOrder->status, 'An explicitly rebound replacement attempt may repay a refunded order.');
ipnAssertSame('transaction-replacement', $explicitReplacementOrder->transactionId, 'Explicit replacement binding must remain authoritative.');

// A replacement delivery may recover the same logical event after its first effect was interrupted.
$replacementOrder = new FakeIPNOrder();
$replacementPayload = v2Payload('delivery-replacement-original', 1, 0);
$replacementBody = json_encode($replacementPayload, JSON_THROW_ON_ERROR);
$replacementEffects = 0;
try {
    WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $replacementOrder,
        'transaction-123',
        $replacementPayload,
        $replacementBody,
        static function (FakeIPNOrder $effectOrder) use (&$replacementEffects): void {
            if ($effectOrder->status !== 'on-hold') {
                $replacementEffects++;
                $effectOrder->update_status('on-hold');
            }
            throw new RuntimeException('simulated post-effect interruption');
        }
    );
} catch (RuntimeException $error) {
    ipnAssertSame('simulated post-effect interruption', $error->getMessage(), 'The simulated interruption must happen after the first effect.');
}
$equivalentReplacementPayload = array_merge($replacementPayload, array(
    'deliveryId' => 'delivery-replacement-equivalent',
    'occurredAt' => '2026-07-26T18:31:00Z',
));
$equivalentReplacementBody = json_encode($equivalentReplacementPayload, JSON_THROW_ON_ERROR);
$equivalentReplacementResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $replacementOrder,
    'transaction-123',
    $equivalentReplacementPayload,
    $equivalentReplacementBody,
    static function (FakeIPNOrder $effectOrder) use (&$replacementEffects): void {
        if ($effectOrder->status !== 'on-hold') {
            $replacementEffects++;
            $effectOrder->update_status('on-hold');
        }
    }
);
ipnAssertSame('applied', $equivalentReplacementResult, 'A different delivery ID with equivalent effect semantics must recover the pending event.');
ipnAssertSame(1, $replacementEffects, 'Equivalent replacement recovery must not repeat an effect already made durable.');
$replacementState = $replacementOrder->get_meta(
    WC_Payment_Gateway_App_IPN_V2_Processor::meta_key('transaction-123'),
    true
);
ipnAssertSame('superseded', $replacementState['deliveries'][$replacementPayload['deliveryId']]['phase'] ?? null, 'The interrupted delivery must become terminal after replacement recovery.');
ipnAssertSame('applied', $replacementState['deliveries'][$equivalentReplacementPayload['deliveryId']]['phase'] ?? null, 'The equivalent replacement must retain the applied acknowledgement.');

$contradictoryReplacementPayload = array_merge($equivalentReplacementPayload, array(
    'deliveryId' => 'delivery-replacement-contradictory',
    'status' => 2,
));
$contradictoryReplacementBody = json_encode($contradictoryReplacementPayload, JSON_THROW_ON_ERROR);
$contradictoryReplacementEffects = 0;
$contradictoryReplacementResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $replacementOrder,
    'transaction-123',
    $contradictoryReplacementPayload,
    $contradictoryReplacementBody,
    static function () use (&$contradictoryReplacementEffects): void {
        $contradictoryReplacementEffects++;
    }
);
ipnAssertSame('conflict', $contradictoryReplacementResult, 'A different delivery ID must not redefine the effect semantics of a transaction event version.');
ipnAssertSame(0, $contradictoryReplacementEffects, 'A contradictory replacement must fail before running an effect.');

$legacyStateOrder = new FakeIPNOrder();
$legacyStatePayload = v2Payload('delivery-legacy-state', 1, 1);
$legacyStateBody = json_encode($legacyStatePayload, JSON_THROW_ON_ERROR);
$legacyStateMetaKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key('transaction-123');
$legacyStateOrder->update_meta_data($legacyStateMetaKey, array(
    'formatVersion' => 1,
    'highestEventVersion' => 1,
    'deliveries' => array(
        $legacyStatePayload['deliveryId'] => array(
            'eventVersion' => 1,
            'bodyHash' => hash('sha256', $legacyStateBody),
            'phase' => 'applied',
        ),
    ),
));
$legacyStateResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $legacyStateOrder,
    'transaction-123',
    $legacyStatePayload,
    $legacyStateBody,
    static function (): void {
        throw new RuntimeException('A legacy applied delivery must remain duplicate-safe.');
    }
);
ipnAssertSame('duplicate', $legacyStateResult, 'Persisted format-v1 delivery state must remain readable and duplicate-safe.');
$upgradedLegacyState = $legacyStateOrder->get_meta($legacyStateMetaKey, true);
ipnAssertSame(1, count($upgradedLegacyState['eventIdentities'] ?? array()), 'An exact legacy-state retry must durably backfill its effect identity.');

// A crash-pending event that is superseded remains safely acknowledgeable on every retry.
$pendingOrder = new FakeIPNOrder();
$pendingPayload = v2Payload('delivery-pending', 1, 0);
$pendingBody = json_encode($pendingPayload, JSON_THROW_ON_ERROR);
try {
    WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $pendingOrder,
        'transaction-pending',
        $pendingPayload,
        $pendingBody,
        static function (): void {
            throw new RuntimeException('simulated effect interruption');
        }
    );
} catch (RuntimeException $error) {
    ipnAssertSame('simulated effect interruption', $error->getMessage(), 'The simulated interruption must leave a pending marker.');
}
$supersedingPayload = v2Payload('delivery-superseding', 2, 1);
$supersedingBody = json_encode($supersedingPayload, JSON_THROW_ON_ERROR);
WC_Payment_Gateway_App_IPN_V2_Processor::process($pendingOrder, 'transaction-pending', $supersedingPayload, $supersedingBody, static function (): void {
});
$firstPendingRetry = WC_Payment_Gateway_App_IPN_V2_Processor::process($pendingOrder, 'transaction-pending', $pendingPayload, $pendingBody, static function (): void {
});
$secondPendingRetry = WC_Payment_Gateway_App_IPN_V2_Processor::process($pendingOrder, 'transaction-pending', $pendingPayload, $pendingBody, static function (): void {
});
ipnAssertSame('outdated', $firstPendingRetry, 'A pending event superseded by a newer version must not resume its old effect.');
ipnAssertSame('outdated', $secondPendingRetry, 'Repeated retries of a superseded pending event must remain acknowledgeable.');

// Bounded delivery retention is safe because the monotonic version rejects replay.
$retentionOrder = new FakeIPNOrder();
for ($version = 1; $version <= 101; $version++) {
    $retainedPayload = v2Payload('delivery-retained-' . $version, $version);
    $retainedBody = json_encode($retainedPayload, JSON_THROW_ON_ERROR);
    WC_Payment_Gateway_App_IPN_V2_Processor::process($retentionOrder, 'transaction-retained', $retainedPayload, $retainedBody, static function (): void {
    });
}
$retentionKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key('transaction-retained');
$retainedState = $retentionOrder->get_meta($retentionKey, true);
ipnAssertSame(100, count($retainedState['deliveries']), 'Processed delivery identity retention must remain bounded.');
ipnAssertSame(100, count($retainedState['eventIdentities'] ?? array()), 'Effect-relevant event identity retention must remain bounded with delivery state.');

$replayPayload = v2Payload('delivery-retained-1', 1, 2);
$replayBody = json_encode($replayPayload, JSON_THROW_ON_ERROR);
$replayEffects = 0;
$replayResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
    $retentionOrder,
    'transaction-retained',
    $replayPayload,
    $replayBody,
    function () use (&$replayEffects): void {
        $replayEffects++;
    }
);
ipnAssertSame('outdated', $replayResult, 'An evicted old delivery ID must still be rejected by event version.');
ipnAssertSame(0, $replayEffects, 'Retention expiry must not permit old effects to replay.');

// Superseded terminal records are evictable, while the latest recoverable claim is retained.
$supersededRetentionOrder = new FakeIPNOrder();
$pendingPayloads = array();
$pendingBodies = array();
for ($version = 1; $version <= 105; $version++) {
    $pendingPayloads[$version] = v2Payload('delivery-superseded-' . $version, $version, 0);
    $pendingBodies[$version] = json_encode($pendingPayloads[$version], JSON_THROW_ON_ERROR);
    try {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $supersededRetentionOrder,
            'transaction-superseded-retention',
            $pendingPayloads[$version],
            $pendingBodies[$version],
            static function (): void {
                throw new RuntimeException('simulated pending effect interruption');
            }
        );
    } catch (RuntimeException $error) {
        ipnAssertSame('simulated pending effect interruption', $error->getMessage(), 'The newest delivery must remain pending for recovery.');
    }
    if ($version > 1) {
        $supersededResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $supersededRetentionOrder,
            'transaction-superseded-retention',
            $pendingPayloads[$version - 1],
            $pendingBodies[$version - 1],
            static function (): void {
                throw new RuntimeException('A superseded event must not resume its effect.');
            }
        );
        ipnAssertSame('outdated', $supersededResult, 'Each older pending delivery must become safely superseded.');
    }
}
$supersededRetentionState = $supersededRetentionOrder->get_meta(
    WC_Payment_Gateway_App_IPN_V2_Processor::meta_key('transaction-superseded-retention'),
    true
);
ipnAssertTrue(
    count($supersededRetentionState['deliveries']) <= WC_Payment_Gateway_App_IPN_V2_Processor::MAX_RETAINED_DELIVERIES,
    'Terminal delivery retention must remain bounded when records are superseded rather than applied.'
);
ipnAssertSame(
    'pending',
    $supersededRetentionState['deliveries']['delivery-superseded-105']['phase'] ?? null,
    'Retention must not discard the latest recoverable pending delivery.'
);

// Newer event claims proactively supersede older pending work without requiring an old-delivery retry.
$noRetryRetentionOrder = new FakeIPNOrder();
for ($version = 1; $version <= 105; $version++) {
    $noRetryPayload = v2Payload('delivery-no-retry-' . $version, $version, 0);
    $noRetryBody = json_encode($noRetryPayload, JSON_THROW_ON_ERROR);
    try {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $noRetryRetentionOrder,
            'transaction-no-retry-retention',
            $noRetryPayload,
            $noRetryBody,
            static function (): void {
                throw new RuntimeException('simulated pending effect interruption');
            }
        );
    } catch (RuntimeException $error) {
        ipnAssertSame('simulated pending effect interruption', $error->getMessage(), 'Each interrupted newer event must preserve its recoverable claim.');
    }
}
$noRetryRetentionState = $noRetryRetentionOrder->get_meta(
    WC_Payment_Gateway_App_IPN_V2_Processor::meta_key('transaction-no-retry-retention'),
    true
);
$recoverableDeliveryIds = array();
foreach ($noRetryRetentionState['deliveries'] as $retainedDeliveryId => $delivery) {
    if (($delivery['phase'] ?? null) === 'pending') {
        $recoverableDeliveryIds[] = $retainedDeliveryId;
    }
}
ipnAssertTrue(
    count($noRetryRetentionState['deliveries']) <= WC_Payment_Gateway_App_IPN_V2_Processor::MAX_RETAINED_DELIVERIES,
    'Newer failed effects must not leave terminally superseded pending claims beyond the retention bound.'
);
ipnAssertSame(
    array('delivery-no-retry-105'),
    $recoverableDeliveryIds,
    'Only the newest interrupted delivery may remain recoverable without retrying older deliveries.'
);

ipnAssertSame(
    WC_Payment_Gateway_App_IPN_Order_Lock::name(42, 'transaction-a'),
    WC_Payment_Gateway_App_IPN_Order_Lock::name(42, 'transaction-b'),
    'All payment attempts for one order must serialize on one order-scoped lock identity.'
);

// Reusing an existing identity for different signed content is a contract conflict.
$collisionPayload = v2Payload($deliveryId, 1, 2);
$collisionBody = json_encode($collisionPayload, JSON_THROW_ON_ERROR);
$collision = WC_Payment_Gateway_App_IPN_V2_Processor::process($order, 'transaction-123', $collisionPayload, $collisionBody, static function (): void {
});
ipnAssertSame('conflict', $collision, 'A delivery ID reused for different content must not be applied or acknowledged as the original event.');

ipnAssertTrue(!str_contains(serialize($retainedState), $retainedBody), 'Persistent deduplication state must not retain raw webhook payloads.');

echo "WooCommerce IPN v2 receiver contract: PASS\n";
