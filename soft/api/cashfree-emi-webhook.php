<?php
/**
 * Cashfree Customer EMI Webhook Handler
 * Asynchronously processes Cashfree server-to-server notifications
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$orderId     = $payload['data']['order']['order_id'] ?? ($payload['orderId'] ?? '');
$orderStatus = strtoupper(trim($payload['data']['order']['order_status'] ?? ($payload['txStatus'] ?? '')));
$orderAmount = floatval($payload['data']['order']['order_amount'] ?? ($payload['orderAmount'] ?? 0));
$cfOrderId   = $payload['data']['order']['cf_order_id'] ?? '';
$cfPaymentId = $payload['data']['payment']['cf_payment_id'] ?? ($payload['referenceId'] ?? '');

if (empty($orderId)) {
    http_response_code(400);
    echo json_encode(['error' => 'No order_id in webhook']);
    exit;
}

// Double verify with Cashfree API to ensure absolute authenticity
$cfRes = cashfree_get_order($orderId);
if (!$cfRes['success']) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not verify order with Cashfree']);
    exit;
}

$verifiedStatus = strtoupper(trim($cfRes['data']['order_status'] ?? ''));
if ($verifiedStatus !== 'PAID') {
    http_response_code(200);
    echo json_encode(['status' => 'acknowledged_not_paid']);
    exit;
}

$p = db();
$stmt = $p->prepare("SELECT * FROM gateway_orders WHERE order_id = ? LIMIT 1");
$stmt->execute([$orderId]);
$gOrder = $stmt->fetch();

if ($gOrder && $gOrder['status'] === 'PAID') {
    http_response_code(200);
    echo json_encode(['status' => 'already_processed']);
    exit;
}

$financeId  = (int)($gOrder['finance_id'] ?? ($cfRes['data']['order_tags']['finance_id'] ?? 0));
$emiId      = (int)($gOrder['emi_id'] ?? ($cfRes['data']['order_tags']['emi_id'] ?? 0));
$customerId = (int)($gOrder['customer_id'] ?? ($cfRes['data']['order_tags']['customer_id'] ?? 0));
$amount     = $orderAmount ?: floatval($gOrder['amount'] ?? 0);

if ($financeId > 0) {
    try {
        // Approve if pending
        $appStmt = $p->prepare("SELECT * FROM finance_applications WHERE id = ?");
        $appStmt->execute([$financeId]);
        $app = $appStmt->fetch();

        if ($app && in_array($app['status'], ['pending', 'kyc_completed'])) {
            $p->prepare("UPDATE finance_applications SET status = 'pending_approval' WHERE id = ?")->execute([$financeId]);
        }

        // Apply EMI
        if ($emiId > 0) {
            $p->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")
              ->execute([$amount, $emiId]);
        } else {
            $unpaidStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC");
            $unpaidStmt->execute([$financeId]);
            $unpaidEmis = $unpaidStmt->fetchAll();

            $remainingCash = $amount;
            foreach ($unpaidEmis as $uEmi) {
                $eAmt = floatval($uEmi['amount']);
                if ($remainingCash >= $eAmt) {
                    $p->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")
                      ->execute([$eAmt, $uEmi['id']]);
                    $remainingCash -= $eAmt;
                } elseif ($remainingCash > 0) {
                    $p->prepare("UPDATE emi_schedules SET paid_amount = paid_amount + ?, paid_at = NOW() WHERE id = ?")
                      ->execute([$remainingCash, $uEmi['id']]);
                    $remainingCash = 0;
                    break;
                }
            }
        }

        // Loan closure check
        $checkUnpaid = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
        $checkUnpaid->execute([$financeId]);
        if ($checkUnpaid->fetchColumn() == 0) {
            $p->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
        }

        // Record payment
        $refNo = !empty($cfPaymentId) ? $cfPaymentId : $orderId;
        $stmtPay = $p->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at) VALUES (?, ?, ?, ?, 'Cashfree Webhook', ?, 'Cashfree Asynchronous Webhook Payment Confirmation', NOW())");
        $stmtPay->execute([
            $financeId,
            $emiId > 0 ? $emiId : null,
            $customerId > 0 ? $customerId : null,
            $amount,
            $refNo
        ]);

        // Update gateway_orders
        if ($gOrder) {
            $p->prepare("UPDATE gateway_orders SET status = 'PAID', cf_order_id = ?, payment_mode = 'Cashfree Webhook', reference_no = ?, response_json = ? WHERE order_id = ?")
              ->execute([$cfOrderId, $refNo, $rawBody, $orderId]);
        }

        log_audit('Cashfree Webhook Processed', 'Payments', "Processed webhook payment of Rs. {$amount} for Loan #{$financeId} (Ref: {$refNo})");

    } catch (Exception $e) {
        error_log("Cashfree Webhook Exception: " . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
