<?php
/**
 * Cashfree POS Terminal Activation Webhook Listener
 * Asynchronously processes Cashfree payment notifications for POS activations.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';
require_once __DIR__ . '/../includes/pos_db_init.php';

header('Content-Type: application/json');

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

$data = json_decode($rawPayload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$orderId = $data['data']['order']['order_id'] ?? ($data['order_id'] ?? ($data['orderId'] ?? ''));

if (empty($orderId)) {
    echo json_encode(['status' => 'ignored', 'message' => 'No order ID found']);
    exit;
}

$p = db();

// Check if this order exists in gateway_orders
$stmt = $p->prepare("SELECT * FROM gateway_orders WHERE order_id = ? LIMIT 1");
$stmt->execute([$orderId]);
$gOrder = $stmt->fetch();

$shopId = (int)($gOrder['shop_id'] ?? 0);

// Verify with Cashfree API
$cfRes = cashfree_get_order($orderId);
if (!$cfRes['success']) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Could not verify order with Cashfree']);
    exit;
}

$orderData   = $cfRes['data'];
$orderStatus = strtoupper(trim($orderData['order_status'] ?? ''));
$cfOrderId   = $orderData['cf_order_id'] ?? '';
$orderAmount = floatval($orderData['order_amount'] ?? 1999);

if ($shopId <= 0 && !empty($orderData['order_tags']['shop_id'])) {
    $shopId = (int)$orderData['order_tags']['shop_id'];
}

if ($orderStatus === 'PAID' && $shopId > 0) {
    try {
        $p->prepare("UPDATE shops SET pos_active = 1, pos_activated_at = NOW(), pos_order_id = ? WHERE id = ?")
          ->execute([$orderId, $shopId]);

        $p->prepare("UPDATE gateway_orders SET status = 'PAID', cf_order_id = ?, updated_at = NOW() WHERE order_id = ?")
          ->execute([$cfOrderId, $orderId]);

        log_audit(
            'POS Addon Activated via Cashfree Webhook',
            'POS Terminal',
            "Shop #{$shopId} POS automatically activated via Cashfree Webhook (Order: {$orderId}, Amount: ₹{$orderAmount})"
        );

        echo json_encode(['status' => 'success', 'message' => 'POS activated for shop ' . $shopId]);
        exit;
    } catch (Exception $e) {
        error_log("POS Webhook Error: " . $e->getMessage());
    }
}

echo json_encode(['status' => 'received', 'order_status' => $orderStatus]);
