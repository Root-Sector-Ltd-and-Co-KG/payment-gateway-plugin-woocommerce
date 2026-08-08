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
        function (WC_Order $effectOrder) use (&$effects, $orderId, $metaKey): void {
            $persistedOrder = wc_get_order($orderId);
            hposAssert($persistedOrder instanceof WC_Order, 'The claim must be reloadable through WooCommerce CRUD.');
            $pendingState = $persistedOrder->get_meta($metaKey, true);
            hposAssertSame('pending', $pendingState['deliveries']['hpos-delivery-1-' . $orderId]['phase'] ?? null, 'The claim must be durable before the effect.');
            hposAssertSame(1, $pendingState['highestEventVersion'] ?? null, 'The durable claim must advance the event version.');
            $effects++;
            $effectOrder->update_status('processing');
        }
    );
    hposAssertSame('applied', $firstResult, 'The first delivery must apply.');
    hposAssertSame(1, $effects, 'The first delivery must apply exactly one effect.');

    $reloadedOrder = wc_get_order($orderId);
    hposAssert($reloadedOrder instanceof WC_Order, 'The order must reload after the first delivery.');
    $appliedState = $reloadedOrder->get_meta($metaKey, true);
    hposAssertSame('applied', $appliedState['deliveries'][$firstPayload['deliveryId']]['phase'] ?? null, 'The applied marker must survive an HPOS reload.');
    hposAssertSame('processing', $reloadedOrder->get_status(), 'The applied order effect must survive an HPOS reload.');

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
