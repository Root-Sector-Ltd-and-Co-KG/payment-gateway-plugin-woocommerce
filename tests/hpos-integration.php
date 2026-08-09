<?php

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

function hposAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hposAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
        );
    }
}

hposAssert(class_exists('WooCommerce'), 'WooCommerce must be active.');
hposAssert(class_exists('WC_Payment_Gateway_App_IPN_V2_Processor'), 'The payment gateway plugin must be active.');
hposAssert(OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS must be enabled.');

$order = wc_create_order();
hposAssert($order instanceof WC_Order, 'A real WooCommerce order must be created.');
hposAssertSame(
    OrdersTableDataStore::class,
    $order->get_data_store()->get_current_class_name(),
    'Orders must use the HPOS data store.'
);
$orderId = $order->get_id();
$transactionId = 'hpos-transaction-' . $orderId;
$metaKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key($transactionId);

try {
    $order->set_currency('EUR');
    $order->set_total('12.34');
    $order->set_status('pending');
    $order->save();

    $firstPayload = array(
        'schemaVersion' => 2,
        'deliveryId' => 'hpos-delivery-1-' . $orderId,
        'eventVersion' => 1,
        'occurredAt' => '2026-08-08T12:00:00Z',
        'id' => $transactionId,
        'externalReference' => (string) $orderId,
        'status' => 1,
    );
    $firstBody = wp_json_encode($firstPayload, JSON_THROW_ON_ERROR);
    $effects = 0;

    $firstResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $order,
        $transactionId,
        $firstPayload,
        $firstBody,
        function (WC_Order $effectOrder) use (&$effects, $orderId, $metaKey, $transactionId): void {
            $persistedOrder = wc_get_order($orderId);
            hposAssert($persistedOrder instanceof WC_Order, 'The claim must be reloadable through WooCommerce CRUD.');
            $pendingState = $persistedOrder->get_meta($metaKey, true);
            hposAssertSame('pending', $pendingState['deliveries']['hpos-delivery-1-' . $orderId]['phase'] ?? null, 'The claim must be durable before the effect.');
            hposAssertSame(1, $pendingState['highestEventVersion'] ?? null, 'The durable claim must advance the event version.');
            $effects++;
            $effectOrder->payment_complete($transactionId);
        }
    );
    hposAssertSame('applied', $firstResult, 'The first delivery must apply.');
    hposAssertSame(1, $effects, 'The first delivery must apply exactly one effect.');

    $reloadedOrder = wc_get_order($orderId);
    hposAssert($reloadedOrder instanceof WC_Order, 'The order must reload after the first delivery.');
    $appliedState = $reloadedOrder->get_meta($metaKey, true);
    hposAssertSame('applied', $appliedState['deliveries'][$firstPayload['deliveryId']]['phase'] ?? null, 'The applied marker must survive an HPOS reload.');
    hposAssert($reloadedOrder->is_paid(), 'The applied payment effect must survive an HPOS reload.');
    $paidStatus = $reloadedOrder->get_status();
    hposAssertSame($transactionId, $reloadedOrder->get_transaction_id(), 'The paid gateway transaction must become the durable active payment attempt.');

    $otherTransactionId = 'hpos-other-transaction-' . $orderId;
    $otherMetaKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key($otherTransactionId);
    $lateOtherPayload = array_merge($firstPayload, array(
        'deliveryId' => 'hpos-other-delivery-' . $orderId,
        'id' => $otherTransactionId,
        'status' => 2,
    ));
    $lateOtherBody = wp_json_encode($lateOtherPayload, JSON_THROW_ON_ERROR);
    $lateOtherEffects = 0;
    $lateOtherResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $reloadedOrder,
        $otherTransactionId,
        $lateOtherPayload,
        $lateOtherBody,
        static function (WC_Order $effectOrder) use (&$lateOtherEffects): void {
            $lateOtherEffects++;
            $effectOrder->update_status('failed');
        }
    );
    hposAssertSame('applied', $lateOtherResult, 'A stale payment-attempt delivery must be durably acknowledged.');
    hposAssertSame(0, $lateOtherEffects, 'A stale payment attempt must not run an order-level effect.');

    $reloadedOrder = wc_get_order($orderId);
    hposAssert($reloadedOrder instanceof WC_Order, 'The order must reload after a stale payment-attempt delivery.');
    hposAssertSame($paidStatus, $reloadedOrder->get_status(), 'A late failure for another transaction must not regress the paid HPOS order.');
    hposAssertSame($transactionId, $reloadedOrder->get_transaction_id(), 'A stale attempt must not replace the active HPOS transaction identity.');
    $otherState = $reloadedOrder->get_meta($otherMetaKey, true);
    hposAssertSame('applied', $otherState['deliveries'][$lateOtherPayload['deliveryId']]['phase'] ?? null, 'A suppressed stale attempt must retain an applied acknowledgement marker in HPOS.');

    $lateOtherDuplicate = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $reloadedOrder,
        $otherTransactionId,
        $lateOtherPayload,
        $lateOtherBody,
        static function () use (&$lateOtherEffects): void {
            $lateOtherEffects++;
        }
    );
    hposAssertSame('duplicate', $lateOtherDuplicate, 'An acknowledgement-loss retry for a stale payment attempt must remain duplicate-safe in HPOS.');
    hposAssertSame(0, $lateOtherEffects, 'A duplicate stale-attempt delivery must not run an effect.');

    $duplicateResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $reloadedOrder,
        $transactionId,
        $firstPayload,
        $firstBody,
        function () use (&$effects): void {
            $effects++;
        }
    );
    hposAssertSame('duplicate', $duplicateResult, 'A duplicate retry must be acknowledged.');
    hposAssertSame(1, $effects, 'A duplicate retry must not repeat the effect.');

    $newerPayload = array_merge($firstPayload, array(
        'deliveryId' => 'hpos-delivery-2-' . $orderId,
        'eventVersion' => 2,
        'status' => -1,
    ));
    $newerBody = wp_json_encode($newerPayload, JSON_THROW_ON_ERROR);
    $newerResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $reloadedOrder,
        $transactionId,
        $newerPayload,
        $newerBody,
        static function (WC_Order $effectOrder): void {
            $effectOrder->update_status('failed');
        }
    );
    hposAssertSame('applied', $newerResult, 'A newer event must apply.');

    $afterNewerOrder = wc_get_order($orderId);
    hposAssert($afterNewerOrder instanceof WC_Order, 'The order must reload after the newer event.');
    hposAssertSame('failed', $afterNewerOrder->get_status(), 'The newer status must be durable.');

    $lateOtherSuccessPayload = array_merge($lateOtherPayload, array(
        'deliveryId' => 'hpos-other-success-' . $orderId,
        'eventVersion' => 2,
        'status' => 1,
    ));
    $lateOtherSuccessBody = wp_json_encode($lateOtherSuccessPayload, JSON_THROW_ON_ERROR);
    $lateOtherSuccessResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $afterNewerOrder,
        $otherTransactionId,
        $lateOtherSuccessPayload,
        $lateOtherSuccessBody,
        static function (WC_Order $effectOrder) use (&$lateOtherEffects, $otherTransactionId): void {
            $lateOtherEffects++;
            $effectOrder->payment_complete($otherTransactionId);
        }
    );
    hposAssertSame('applied', $lateOtherSuccessResult, 'A delayed success for a stale payment attempt must be acknowledged in HPOS.');
    hposAssertSame(0, $lateOtherEffects, 'A delayed success for a stale payment attempt must not run an HPOS payment effect.');

    $afterLateOtherSuccess = wc_get_order($orderId);
    hposAssert($afterLateOtherSuccess instanceof WC_Order, 'The order must reload after the stale delayed success.');
    hposAssertSame('failed', $afterLateOtherSuccess->get_status(), 'A stale delayed success must not reopen the HPOS order.');
    hposAssertSame($transactionId, $afterLateOtherSuccess->get_transaction_id(), 'A stale delayed success must not rebind the HPOS transaction identity.');

    $olderPayload = array_merge($firstPayload, array(
        'deliveryId' => 'hpos-delivery-stale-' . $orderId,
        'status' => 2,
    ));
    $olderBody = wp_json_encode($olderPayload, JSON_THROW_ON_ERROR);
    $olderEffects = 0;
    $olderResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $afterNewerOrder,
        $transactionId,
        $olderPayload,
        $olderBody,
        function () use (&$olderEffects): void {
            $olderEffects++;
        }
    );
    hposAssertSame('outdated', $olderResult, 'An older out-of-order event must be acknowledged without applying.');
    hposAssertSame(0, $olderEffects, 'An older event must not repeat or regress effects.');

    $pendingTransactionId = 'hpos-pending-transaction-' . $orderId;
    $pendingMetaKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key($pendingTransactionId);
    $afterLateOtherSuccess->set_transaction_id($pendingTransactionId);
    $afterLateOtherSuccess->save();
    $pendingOne = array_merge($firstPayload, array(
        'deliveryId' => 'hpos-pending-1-' . $orderId,
        'id' => $pendingTransactionId,
        'status' => 0,
    ));
    $pendingOneBody = wp_json_encode($pendingOne, JSON_THROW_ON_ERROR);
    try {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $afterLateOtherSuccess,
            $pendingTransactionId,
            $pendingOne,
            $pendingOneBody,
            static function (): void {
                throw new RuntimeException('simulated first pending interruption');
            }
        );
    } catch (RuntimeException $error) {
        hposAssertSame('simulated first pending interruption', $error->getMessage(), 'The first interrupted HPOS claim must remain pending.');
    }
    $pendingTwo = array_merge($pendingOne, array(
        'deliveryId' => 'hpos-pending-2-' . $orderId,
        'eventVersion' => 2,
    ));
    $pendingTwoBody = wp_json_encode($pendingTwo, JSON_THROW_ON_ERROR);
    try {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $afterLateOtherSuccess,
            $pendingTransactionId,
            $pendingTwo,
            $pendingTwoBody,
            static function (): void {
                throw new RuntimeException('simulated second pending interruption');
            }
        );
    } catch (RuntimeException $error) {
        hposAssertSame('simulated second pending interruption', $error->getMessage(), 'The newest interrupted HPOS claim must remain pending.');
    }

    $afterPendingClaims = wc_get_order($orderId);
    hposAssert($afterPendingClaims instanceof WC_Order, 'The order must reload after interrupted pending claims.');
    $pendingState = $afterPendingClaims->get_meta($pendingMetaKey, true);
    hposAssertSame('superseded', $pendingState['deliveries'][$pendingOne['deliveryId']]['phase'] ?? null, 'A newer HPOS event must proactively supersede the older pending claim.');
    hposAssertSame('pending', $pendingState['deliveries'][$pendingTwo['deliveryId']]['phase'] ?? null, 'The newest interrupted HPOS event must remain recoverable.');

    $replacementTransactionId = 'hpos-replacement-transaction-' . $orderId;
    $replacementMetaKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key($replacementTransactionId);
    $afterPendingClaims->set_transaction_id($replacementTransactionId);
    $afterPendingClaims->set_status('pending');
    $afterPendingClaims->save();
    $replacementPayload = array_merge($firstPayload, array(
        'deliveryId' => 'hpos-replacement-original-' . $orderId,
        'id' => $replacementTransactionId,
        'status' => 0,
    ));
    $replacementBody = wp_json_encode($replacementPayload, JSON_THROW_ON_ERROR);
    $replacementEffects = 0;
    try {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $afterPendingClaims,
            $replacementTransactionId,
            $replacementPayload,
            $replacementBody,
            static function (WC_Order $effectOrder) use (&$replacementEffects): void {
                if ($effectOrder->get_status() !== 'on-hold') {
                    $replacementEffects++;
                    $effectOrder->update_status('on-hold');
                }
                throw new RuntimeException('simulated replacement post-effect interruption');
            }
        );
    } catch (RuntimeException $error) {
        hposAssertSame('simulated replacement post-effect interruption', $error->getMessage(), 'The original HPOS delivery must be interrupted after its effect.');
    }

    $afterReplacementInterruption = wc_get_order($orderId);
    hposAssert($afterReplacementInterruption instanceof WC_Order, 'The interrupted replacement order must reload through HPOS.');
    hposAssertSame('on-hold', $afterReplacementInterruption->get_status(), 'The interrupted effect must be durable before replacement recovery.');
    $equivalentReplacementPayload = array_merge($replacementPayload, array(
        'deliveryId' => 'hpos-replacement-equivalent-' . $orderId,
        'occurredAt' => '2026-08-08T12:01:00Z',
    ));
    $equivalentReplacementBody = wp_json_encode($equivalentReplacementPayload, JSON_THROW_ON_ERROR);
    $equivalentReplacementResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $afterReplacementInterruption,
        $replacementTransactionId,
        $equivalentReplacementPayload,
        $equivalentReplacementBody,
        static function (WC_Order $effectOrder) use (&$replacementEffects): void {
            if ($effectOrder->get_status() !== 'on-hold') {
                $replacementEffects++;
                $effectOrder->update_status('on-hold');
            }
        }
    );
    hposAssertSame('applied', $equivalentReplacementResult, 'Equivalent replacement recovery must apply through HPOS.');
    hposAssertSame(1, $replacementEffects, 'Equivalent HPOS replacement recovery must not repeat a durable effect.');

    $afterEquivalentReplacement = wc_get_order($orderId);
    hposAssert($afterEquivalentReplacement instanceof WC_Order, 'The equivalent replacement result must reload through HPOS.');
    $replacementState = $afterEquivalentReplacement->get_meta($replacementMetaKey, true);
    hposAssertSame('superseded', $replacementState['deliveries'][$replacementPayload['deliveryId']]['phase'] ?? null, 'The interrupted HPOS delivery must become terminal after replacement.');
    hposAssertSame('applied', $replacementState['deliveries'][$equivalentReplacementPayload['deliveryId']]['phase'] ?? null, 'The equivalent HPOS replacement must retain its applied acknowledgement.');

    $contradictoryReplacementPayload = array_merge($equivalentReplacementPayload, array(
        'deliveryId' => 'hpos-replacement-contradictory-' . $orderId,
        'status' => 2,
    ));
    $contradictoryReplacementBody = wp_json_encode($contradictoryReplacementPayload, JSON_THROW_ON_ERROR);
    $contradictoryReplacementEffects = 0;
    $contradictoryReplacementResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $afterEquivalentReplacement,
        $replacementTransactionId,
        $contradictoryReplacementPayload,
        $contradictoryReplacementBody,
        static function () use (&$contradictoryReplacementEffects): void {
            $contradictoryReplacementEffects++;
        }
    );
    hposAssertSame('conflict', $contradictoryReplacementResult, 'Contradictory replacement semantics must return the HPOS identity-conflict result.');
    hposAssertSame(0, $contradictoryReplacementEffects, 'A contradictory HPOS replacement must fail before its effect.');
    hposAssertSame('on-hold', wc_get_order($orderId)->get_status(), 'A contradictory replacement must not mutate the HPOS order.');

    $legacyPendingTransactionId = 'hpos-legacy-pending-transaction-' . $orderId;
    $legacyPendingMetaKey = WC_Payment_Gateway_App_IPN_V2_Processor::meta_key($legacyPendingTransactionId);
    $legacyPendingPayload = array_merge($firstPayload, array(
        'deliveryId' => 'hpos-legacy-pending-original-' . $orderId,
        'id' => $legacyPendingTransactionId,
        'status' => 0,
    ));
    $legacyPendingBody = wp_json_encode($legacyPendingPayload, JSON_THROW_ON_ERROR);
    $afterEquivalentReplacement->set_transaction_id($legacyPendingTransactionId);
    $afterEquivalentReplacement->set_status('pending');
    $afterEquivalentReplacement->update_meta_data($legacyPendingMetaKey, array(
        'formatVersion' => 1,
        'highestEventVersion' => 1,
        'deliveries' => array(
            $legacyPendingPayload['deliveryId'] => array(
                'eventVersion' => 1,
                'bodyHash' => hash('sha256', $legacyPendingBody),
                'phase' => 'pending',
            ),
        ),
    ));
    $afterEquivalentReplacement->save();

    $legacyPendingOrder = wc_get_order($orderId);
    hposAssert($legacyPendingOrder instanceof WC_Order, 'The injected legacy pending state must reload through HPOS.');
    $unprovableLegacyReplacement = array_merge($legacyPendingPayload, array(
        'deliveryId' => 'hpos-legacy-pending-unprovable-' . $orderId,
        'occurredAt' => '2026-08-08T12:02:00Z',
    ));
    $unprovableLegacyReplacementBody = wp_json_encode($unprovableLegacyReplacement, JSON_THROW_ON_ERROR);
    $unprovableLegacyEffects = 0;
    $unprovableLegacyResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $legacyPendingOrder,
        $legacyPendingTransactionId,
        $unprovableLegacyReplacement,
        $unprovableLegacyReplacementBody,
        static function () use (&$unprovableLegacyEffects): void {
            $unprovableLegacyEffects++;
        }
    );
    hposAssertSame('conflict', $unprovableLegacyResult, 'An unprovable different-ID legacy pending replacement must conflict through HPOS.');
    hposAssertSame(0, $unprovableLegacyEffects, 'An unprovable legacy HPOS replacement must not run an effect.');

    try {
        WC_Payment_Gateway_App_IPN_V2_Processor::process(
            $legacyPendingOrder,
            $legacyPendingTransactionId,
            $legacyPendingPayload,
            $legacyPendingBody,
            static function (): void {
                throw new RuntimeException('simulated HPOS legacy pending retry interruption');
            }
        );
    } catch (RuntimeException $error) {
        hposAssertSame('simulated HPOS legacy pending retry interruption', $error->getMessage(), 'The exact legacy HPOS retry must reach effect recovery.');
    }

    $backfilledLegacyPendingOrder = wc_get_order($orderId);
    hposAssert($backfilledLegacyPendingOrder instanceof WC_Order, 'The backfilled legacy pending state must reload through HPOS.');
    $backfilledLegacyPendingState = $backfilledLegacyPendingOrder->get_meta($legacyPendingMetaKey, true);
    hposAssertSame(1, count($backfilledLegacyPendingState['eventIdentities'] ?? array()), 'The exact HPOS retry must durably backfill legacy effect identity.');
    hposAssertSame('pending', $backfilledLegacyPendingState['deliveries'][$legacyPendingPayload['deliveryId']]['phase'] ?? null, 'The interrupted exact HPOS retry must remain recoverable.');

    $provableLegacyReplacement = array_merge($legacyPendingPayload, array(
        'deliveryId' => 'hpos-legacy-pending-provable-' . $orderId,
        'occurredAt' => '2026-08-08T12:03:00Z',
    ));
    $provableLegacyReplacementBody = wp_json_encode($provableLegacyReplacement, JSON_THROW_ON_ERROR);
    $provableLegacyResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $backfilledLegacyPendingOrder,
        $legacyPendingTransactionId,
        $provableLegacyReplacement,
        $provableLegacyReplacementBody,
        static function (WC_Order $effectOrder): void {
            $effectOrder->update_status('on-hold');
        }
    );
    hposAssertSame('applied', $provableLegacyResult, 'A legacy HPOS replacement may recover after exact-delivery identity backfill.');

    $afterProvableLegacyReplacement = wc_get_order($orderId);
    hposAssert($afterProvableLegacyReplacement instanceof WC_Order, 'The recovered legacy HPOS replacement must reload.');
    $legacyPendingContradiction = array_merge($provableLegacyReplacement, array(
        'deliveryId' => 'hpos-legacy-pending-contradictory-' . $orderId,
        'status' => 2,
    ));
    $legacyPendingContradictionBody = wp_json_encode($legacyPendingContradiction, JSON_THROW_ON_ERROR);
    $legacyPendingContradictionResult = WC_Payment_Gateway_App_IPN_V2_Processor::process(
        $afterProvableLegacyReplacement,
        $legacyPendingTransactionId,
        $legacyPendingContradiction,
        $legacyPendingContradictionBody,
        static function (): void {
            throw new RuntimeException('A contradictory legacy HPOS replacement must not run an effect.');
        }
    );
    hposAssertSame('conflict', $legacyPendingContradictionResult, 'A legacy HPOS contradiction must conflict after semantic identity backfill.');

    $afterProvableLegacyReplacement->set_transaction_id($transactionId);
    $afterProvableLegacyReplacement->set_status('failed');
    $afterProvableLegacyReplacement->save();

    $finalOrder = wc_get_order($orderId);
    hposAssert($finalOrder instanceof WC_Order, 'The final order must reload.');
    hposAssertSame('failed', $finalOrder->get_status(), 'An older event must not regress the durable order status.');

    global $wpdb;
    $hposMetaTable = $wpdb->prefix . 'wc_orders_meta';
    $hposMetaRows = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$hposMetaTable} WHERE order_id = %d AND meta_key = %s",
        $orderId,
        $metaKey
    ));
    hposAssert($hposMetaRows > 0, 'The delivery state must be persisted in the HPOS metadata table.');
    hposAssertSame('', get_post_meta($orderId, $metaKey, true), 'The test must not pass through synchronized legacy post metadata.');
} finally {
    $orderToDelete = wc_get_order($orderId);
    if ($orderToDelete instanceof WC_Order) {
        $orderToDelete->delete(true);
    }
}

echo "WooCommerce HPOS IPN integration: PASS\n";
