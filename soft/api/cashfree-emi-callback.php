<?php
/**
 * Cashfree Customer EMI Payment Return Callback
 * Handles customer return after Cashfree PG checkout, verifies payment server-side,
 * records payment receipt, updates EMI schedule, and redirects customer to portal.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';

$orderId = trim($_GET['order_id'] ?? ($_POST['order_id'] ?? ''));

if (empty($orderId)) {
    die("Invalid access: No order ID provided.");
}

$p = db();

// Lookup order in gateway_orders
$stmt = $p->prepare("SELECT * FROM gateway_orders WHERE order_id = ? LIMIT 1");
$stmt->execute([$orderId]);
$gOrder = $stmt->fetch();

$financeId  = (int)($gOrder['finance_id'] ?? 0);
$emiId      = (int)($gOrder['emi_id'] ?? 0);
$customerId = (int)($gOrder['customer_id'] ?? 0);
$amount     = floatval($gOrder['amount'] ?? 0);

// Verify Order with Cashfree API server-to-server
$cfRes = cashfree_get_order($orderId);

if (!$cfRes['success']) {
    $err = $cfRes['message'] ?? 'Could not verify payment with Cashfree.';
    $redirectUrl = url('/customer/emi-schedule.php' . ($financeId > 0 ? '?finance_id=' . $financeId : ''));
    header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') !== false ? '&' : '?') . "payment=error&err=" . urlencode($err));
    exit;
}

$orderData   = $cfRes['data'];
$orderStatus = strtoupper(trim($orderData['order_status'] ?? ''));
$cfOrderId   = $orderData['cf_order_id'] ?? '';
$orderAmount = floatval($orderData['order_amount'] ?? $amount);

// Extract tags if gateway_orders record was somehow missing
if ($financeId <= 0 && !empty($orderData['order_tags']['finance_id'])) {
    $financeId  = (int)$orderData['order_tags']['finance_id'];
    $emiId      = (int)($orderData['order_tags']['emi_id'] ?? 0);
    $customerId = (int)($orderData['order_tags']['customer_id'] ?? 0);
}

// Fetch Payment Details from Cashfree
$paymentsRes = cashfree_get_order_payments($orderId);
$cfPaymentId = '';
$paymentMode = 'Cashfree PG';

if ($paymentsRes['success'] && !empty($paymentsRes['data']) && is_array($paymentsRes['data'])) {
    $latestPayment = end($paymentsRes['data']);
    $cfPaymentId   = (string)($latestPayment['cf_payment_id'] ?? '');
    
    // Determine payment mode (UPI, Netbanking, Card, etc.)
    if (!empty($latestPayment['payment_method'])) {
        $pm = $latestPayment['payment_method'];
        if (is_array($pm)) {
            $keys = array_keys($pm);
            $paymentMode = 'Cashfree ' . strtoupper($keys[0] ?? 'ONLINE');
        } elseif (is_string($pm)) {
            $paymentMode = 'Cashfree ' . strtoupper($pm);
        }
    }
}

if ($orderStatus === 'PAID') {
    // Check if already processed (Idempotency check)
    $alreadyPaid = false;
    if ($gOrder && $gOrder['status'] === 'PAID') {
        $alreadyPaid = true;
    }

    if (!$alreadyPaid && $financeId > 0) {
        try {
            // 1. If Down Payment or application pending, approve application & notify customer
            $appStmt = $p->prepare("SELECT * FROM finance_applications WHERE id = ?");
            $appStmt->execute([$financeId]);
            $app = $appStmt->fetch();

            if ($app && in_array($app['status'], ['pending', 'kyc_completed'])) {
                approve_finance_application_and_notify($financeId);
            }

            // 2. Identify target EMI & Process Payment
            if ($emiId > 0) {
                $p->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")
                  ->execute([$orderAmount, $emiId]);
            } else {
                // Sequential settlement for unpaid EMIs
                $unpaidStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC");
                $unpaidStmt->execute([$financeId]);
                $unpaidEmis = $unpaidStmt->fetchAll();

                $remainingCash = $orderAmount;
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

            // 3. Check if all EMIs for this loan are now paid -> Mark loan completed
            $checkUnpaid = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
            $checkUnpaid->execute([$financeId]);
            if ($checkUnpaid->fetchColumn() == 0) {
                $p->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
            }

            // 4. Record Payment in payments table
            $refNo = !empty($cfPaymentId) ? $cfPaymentId : $orderId;
            $stmtPay = $p->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at) VALUES (?, ?, ?, ?, ?, ?, 'Cashfree Online EMI Payment', NOW())");
            $stmtPay->execute([
                $financeId,
                $emiId > 0 ? $emiId : null,
                $customerId > 0 ? $customerId : null,
                $orderAmount,
                $paymentMode,
                $refNo
            ]);

            // 5. Update gateway_orders status
            $p->prepare("UPDATE gateway_orders SET status = 'PAID', cf_order_id = ?, payment_mode = ?, reference_no = ?, response_json = ? WHERE order_id = ?")
              ->execute([$cfOrderId, $paymentMode, $refNo, json_encode($orderData), $orderId]);

            log_audit('Cashfree EMI Payment Received', 'Payments', "Received EMI Payment of Rs. {$orderAmount} for Loan #{$financeId} via {$paymentMode} (Ref: {$refNo})");

        } catch (Exception $e) {
            error_log("Cashfree Callback Processing Error: " . $e->getMessage());
        }
    }

    $rHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $rScheme = (in_array($rHost, ['localhost', '127.0.0.1'])) ? 'http://' : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');
    $redirectUrl = $rScheme . $rHost . url('/customer/emi-schedule.php?finance_id=' . $financeId . '&payment=success&amount=' . $orderAmount . '&order_id=' . urlencode($orderId));
    header("Location: " . $redirectUrl);
    exit;

} else {
    // Payment Failed or User Cancelled
    $statusText = $orderStatus ?: 'FAILED';
    if ($gOrder) {
        $p->prepare("UPDATE gateway_orders SET status = ?, cf_order_id = ?, response_json = ? WHERE order_id = ?")
          ->execute([$statusText, $cfOrderId, json_encode($orderData), $orderId]);
    }

    $rHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $rScheme = (in_array($rHost, ['localhost', '127.0.0.1'])) ? 'http://' : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');
    $redirectUrl = $rScheme . $rHost . url('/customer/emi-schedule.php?finance_id=' . $financeId . '&payment=failed&status=' . urlencode($statusText) . '&order_id=' . urlencode($orderId));
    header("Location: " . $redirectUrl);
    exit;
}
