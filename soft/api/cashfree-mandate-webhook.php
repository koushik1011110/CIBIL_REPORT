<?php
/**
 * Cashfree Customer Subscriptions & Autopay Webhook Handler
 * 
 * Asynchronously processes Cashfree mandate events:
 * 1. Mandate status changes (ACTIVE, CANCELLED)
 * 2. Scheduled/recurring auto-debit payments (SUBSCRIPTION_PAYMENT_SUCCESS)
 *    - Settles the due EMI in emi_schedules
 *    - Records payment receipt in payments table
 *    - Records debit in mandate_debits table
 *    - Notifies customer and logs audit trail
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';

ensure_mandate_tables();

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty webhook payload']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$eventType = strtoupper(trim($payload['type'] ?? ($payload['event'] ?? '')));
$data = $payload['data'] ?? $payload;

$p = db();

// Case 1: Mandate Status Change (e.g. SUBSCRIPTION_STATUS_CHANGE, SUBSCRIPTION_CANCELLED)
if (strpos($eventType, 'SUBSCRIPTION_STATUS') !== false || strpos($eventType, 'SUBSCRIPTION_CANCELLED') !== false || isset($data['subscription_id'])) {
    $sub = $data['subscription'] ?? $data;
    $subId = trim($sub['subscription_id'] ?? '');
    $status = strtoupper(trim($sub['subscription_status'] ?? ($sub['status'] ?? '')));

    if (!empty($subId)) {
        $stmt = $p->prepare("SELECT * FROM loan_mandates WHERE mandate_id = ? LIMIT 1");
        $stmt->execute([$subId]);
        $mandate = $stmt->fetch();

        if ($mandate) {
            if ($status === 'CANCELLED' && $mandate['status'] !== 'CANCELLED') {
                $p->prepare("UPDATE loan_mandates SET status = 'CANCELLED', cancelled_at = NOW(), cancellation_reason = 'Cancelled on Cashfree Gateway' WHERE id = ?")
                  ->execute([$mandate['id']]);
                
                log_audit('Autopay Mandate Cancelled', 'Payments', "Mandate {$subId} marked CANCELLED via Webhook");
            } elseif ($status === 'ACTIVE' && $mandate['status'] !== 'ACTIVE') {
                $p->prepare("UPDATE loan_mandates SET status = 'ACTIVE' WHERE id = ?")
                  ->execute([$mandate['id']]);
                
                log_audit('Autopay Mandate Activated', 'Payments', "Mandate {$subId} marked ACTIVE via Webhook");
            }
        }
    }
}

// Case 2: Recurring Auto-Debit Payment Success (e.g. SUBSCRIPTION_PAYMENT_SUCCESS, PAYMENT_SUCCESS_WEBHOOK)
if (strpos($eventType, 'PAYMENT') !== false || isset($data['payment'])) {
    $payment = $data['payment'] ?? $data;
    $sub = $data['subscription'] ?? $data;

    $subId = trim($sub['subscription_id'] ?? ($payment['subscription_id'] ?? ''));
    $paymentStatus = strtoupper(trim($payment['payment_status'] ?? ($payment['status'] ?? '')));
    $amount = floatval($payment['payment_amount'] ?? ($payment['amount'] ?? 0));
    $cfPaymentId = (string)($payment['cf_payment_id'] ?? ($payment['payment_id'] ?? ''));

    if (!empty($subId) && in_array($paymentStatus, ['SUCCESS', 'PAID'])) {
        $stmt = $p->prepare("SELECT * FROM loan_mandates WHERE mandate_id = ? LIMIT 1");
        $stmt->execute([$subId]);
        $mandate = $stmt->fetch();

        if ($mandate) {
            $financeId = (int)$mandate['finance_id'];
            $customerId = (int)$mandate['customer_id'];

            // Check if this payment was already processed
            $chkPay = $p->prepare("SELECT COUNT(*) FROM payments WHERE reference_no = ?");
            $chkPay->execute([$cfPaymentId]);
            $alreadyRecorded = ($chkPay->fetchColumn() > 0);

            if (!$alreadyRecorded && $financeId > 0) {
                try {
                    // 1. Find the earliest unpaid EMI schedule
                    $emiStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC LIMIT 1");
                    $emiStmt->execute([$financeId]);
                    $targetEmi = $emiStmt->fetch();

                    $emiId = $targetEmi ? (int)$targetEmi['id'] : null;

                    if ($targetEmi) {
                        $p->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")
                          ->execute([$amount, $targetEmi['id']]);
                    }

                    // 2. Insert payment receipt in payments table
                    $refNo = !empty($cfPaymentId) ? $cfPaymentId : ('AUTOPAY_' . time());
                    $stmtPay = $p->prepare("
                        INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at)
                        VALUES (?, ?, ?, ?, 'Cashfree e-Mandate Autopay', ?, 'Monthly Auto-Debit Mandate', NOW())
                    ");
                    $stmtPay->execute([
                        $financeId,
                        $emiId,
                        $customerId,
                        $amount,
                        $refNo
                    ]);

                    // 3. Record in mandate_debits
                    $p->prepare("
                        INSERT INTO mandate_debits (mandate_id, finance_id, emi_id, cf_payment_id, amount, status, debited_at)
                        VALUES (?, ?, ?, ?, ?, 'SUCCESS', NOW())
                    ")->execute([
                        $subId,
                        $financeId,
                        $emiId,
                        $refNo,
                        $amount
                    ]);

                    // 4. Update next debit date on mandate
                    $nextDueStmt = $p->prepare("SELECT due_date FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC LIMIT 1");
                    $nextDueStmt->execute([$financeId]);
                    $nextDueDate = $nextDueStmt->fetchColumn();

                    $nextDebitSql = $nextDueDate ? date('Y-m-d 10:00:00', strtotime($nextDueDate)) : null;

                    $p->prepare("UPDATE loan_mandates SET last_debit_date = NOW(), next_debit_date = ? WHERE id = ?")
                      ->execute([$nextDebitSql, $mandate['id']]);

                    // 5. If all EMIs paid, mark loan completed
                    $unpaidLeft = (int)$p->query("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = {$financeId} AND status != 'paid'")->fetchColumn();
                    if ($unpaidLeft === 0) {
                        $p->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
                    }

                    // 6. Notify Customer
                    $appStmt = $p->prepare("SELECT application_no FROM finance_applications WHERE id = ?");
                    $appStmt->execute([$financeId]);
                    $appNo = $appStmt->fetchColumn() ?: "Loan #{$financeId}";

                    $userStmt = $p->prepare("SELECT u.id FROM users u JOIN customers c ON (c.email = u.email OR c.mobile = u.email) WHERE c.id = ? LIMIT 1");
                    $userStmt->execute([$customerId]);
                    $targetUserId = $userStmt->fetchColumn();

                    if ($targetUserId) {
                        $p->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())")
                          ->execute([
                              $targetUserId,
                              'Monthly EMI Auto-Debited',
                              "₹" . number_format($amount, 2) . " has been successfully auto-debited via Cashfree e-Mandate for {$appNo}."
                          ]);
                    }

                    log_audit(
                        'Autopay Debit Succeeded',
                        'Payments',
                        "Auto-debit of Rs. {$amount} for {$appNo} via Mandate {$subId} (Txn: {$refNo})"
                    );

                } catch (Exception $e) {
                    error_log("Autopay Webhook Debit Processing Error: " . $e->getMessage());
                }
            }
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
