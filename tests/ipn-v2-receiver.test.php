<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

function add_action(): void
{
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

function v2Payload(string $deliveryId, int $eventVersion, int $status = 1): array
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
