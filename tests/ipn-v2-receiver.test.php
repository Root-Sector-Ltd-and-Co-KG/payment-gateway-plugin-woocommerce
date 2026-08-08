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

// Reusing an existing identity for different signed content is a contract conflict.
$collisionPayload = v2Payload($deliveryId, 1, 2);
$collisionBody = json_encode($collisionPayload, JSON_THROW_ON_ERROR);
$collision = WC_Payment_Gateway_App_IPN_V2_Processor::process($order, 'transaction-123', $collisionPayload, $collisionBody, static function (): void {
});
ipnAssertSame('conflict', $collision, 'A delivery ID reused for different content must not be applied or acknowledged as the original event.');

ipnAssertTrue(!str_contains(serialize($retainedState), $retainedBody), 'Persistent deduplication state must not retain raw webhook payloads.');

echo "WooCommerce IPN v2 receiver contract: PASS\n";
