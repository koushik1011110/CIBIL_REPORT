<?php
/**
 * Automated Cron & Backend Engine: Process Due Recurring EMI Debits
 * 
 * Can be run via CLI Cron or invoked by Admin / System:
 * Usage: php process-recurring-debit.php [secret_token]
 * Or HTTP: GET /api/process-recurring-debit.php?token=CRON_SECRET
 * 
 * Scans all ACTIVE loan mandates with EMIs due on or before today,
 * triggers recurring charge on Cashfree gateway, settles EMI schedule,
 * and generates payment receipts.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';

ensure_mandate_tables();

$isCli = (php_sapi_name() === 'cli');

// Basic security token check for HTTP calls
if (!$isCli) {
    $cronSecret = get_setting('cron_secret_key', 'GO4FIN_CRON_SECURE_TOKEN_2026');
    $providedToken = $_GET['token'] ?? ($_POST['token'] ?? '');
    $user = function_exists('u') ? u() : null;

    if ($providedToken !== $cronSecret && (!isset($user['role']) || $user['role'] !== 'superadmin')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: Invalid cron authorization token']);
        exit;
    }
}

$p = db();
$today = date('Y-m-d');
$processedCount = 0;
$successCount = 0;
$failureCount = 0;
$results = [];

// 1. Fetch all ACTIVE loan mandates
$stmt = $p->prepare("
    SELECT lm.*, f.application_no, f.customer_id as f_cust_id
    FROM loan_mandates lm
    JOIN finance_applications f ON f.id = lm.finance_id
    WHERE lm.status = 'ACTIVE'
");
$stmt->execute();
$activeMandates = $stmt->fetchAll();

foreach ($activeMandates as $mandate) {
    $financeId = (int)$mandate['finance_id'];
    $mandateId = $mandate['mandate_id'];

    // Find the oldest unpaid EMI that is due on or before today
    $emiStmt = $p->prepare("
        SELECT * FROM emi_schedules 
        WHERE finance_id = ? 
          AND status != 'paid' 
          AND due_date <= ? 
        ORDER BY installment_no ASC 
        LIMIT 1
    ");
    $emiStmt->execute([$financeId, $today]);
    $dueEmi = $emiStmt->fetch();

    if (!$dueEmi) {
        // No dues for today for this loan
        continue;
    }

    $processedCount++;
    $emiId = (int)$dueEmi['id'];
    $chargeAmount = floatval($dueEmi['amount']);

    // Check if debit was already attempted today for this EMI (prevent duplicate debits)
    $chkStmt = $p->prepare("
        SELECT COUNT(*) FROM mandate_debits 
        WHERE mandate_id = ? AND emi_id = ? AND DATE(created_at) = ? AND status = 'SUCCESS'
    ");
    $chkStmt->execute([$mandateId, $emiId, $today]);
    if ($chkStmt->fetchColumn() > 0) {
        $results[] = [
            'mandate_id' => $mandateId,
            'finance_id' => $financeId,
            'emi_id'     => $emiId,
            'status'     => 'SKIPPED',
            'reason'     => 'Already debited today'
        ];
        continue;
    }

    // Generate unique payment / charge ID
    $paymentId = 'CHG_' . $financeId . '_E' . $emiId . '_' . time();

    // Prepare Cashfree Charge Payload
    $chargePayload = [
        'subscription_id' => $mandateId,
        'payment_id'      => $paymentId,
        'payment_amount'  => round($chargeAmount, 2),
        'payment_remarks' => 'Monthly EMI #' . $dueEmi['installment_no'] . ' (' . ($mandate['application_no'] ?: '#' . $financeId) . ')',
        'payment_type'    => 'CHARGE'
    ];

    // Call Cashfree PG to execute debit
    $chargeRes = cashfree_charge_subscription($chargePayload);

    if ($chargeRes['success']) {
        $cData = $chargeRes['data'] ?? [];
        $cfPaymentId = (string)($cData['cf_payment_id'] ?? ($cData['payment_id'] ?? $paymentId));
        $payStatus = strtoupper(trim($cData['payment_status'] ?? ($cData['status'] ?? 'SUCCESS')));

        // Mark EMI as paid
        $p->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")
          ->execute([$chargeAmount, $emiId]);

        // Insert payment receipt in payments table
        $stmtPay = $p->prepare("
            INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at)
            VALUES (?, ?, ?, ?, 'Cashfree e-Mandate Autopay', ?, 'Monthly Auto-Debit Mandate', NOW())
        ");
        $stmtPay->execute([
            $financeId,
            $emiId,
            (int)$mandate['customer_id'],
            $chargeAmount,
            $cfPaymentId
        ]);

        // Insert in mandate_debits
        $p->prepare("
            INSERT INTO mandate_debits (mandate_id, finance_id, emi_id, cf_payment_id, amount, status, debited_at)
            VALUES (?, ?, ?, ?, ?, 'SUCCESS', NOW())
        ")->execute([
            $mandateId,
            $financeId,
            $emiId,
            $cfPaymentId,
            $chargeAmount
        ]);

        // Calculate and update next debit date
        $nextDueStmt = $p->prepare("SELECT due_date FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC LIMIT 1");
        $nextDueStmt->execute([$financeId]);
        $nextDue = $nextDueStmt->fetchColumn();
        $nextDebitSql = $nextDue ? date('Y-m-d 10:00:00', strtotime($nextDue)) : null;

        $p->prepare("UPDATE loan_mandates SET last_debit_date = NOW(), next_debit_date = ? WHERE id = ?")
          ->execute([$nextDebitSql, $mandate['id']]);

        // Check if all EMIs completed
        $unpaidLeft = (int)$p->query("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = {$financeId} AND status != 'paid'")->fetchColumn();
        if ($unpaidLeft === 0) {
            $p->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
        }

        // Customer in-app notification
        try {
            $userStmt = $p->prepare("SELECT u.id FROM users u JOIN customers c ON (c.email = u.email OR c.mobile = u.email) WHERE c.id = ? LIMIT 1");
            $userStmt->execute([(int)$mandate['customer_id']]);
            $targetUserId = $userStmt->fetchColumn();

            if ($targetUserId) {
                $p->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())")
                  ->execute([
                      $targetUserId,
                      'Monthly EMI Auto-Debited',
                      "₹" . number_format($chargeAmount, 2) . " was successfully auto-debited via Cashfree e-Mandate for " . ($mandate['application_no'] ?: "Loan #{$financeId}") . "."
                  ]);
            }
        } catch (Exception $ex) {}

        log_audit(
            'Autopay Debit Executed',
            'Payments',
            "Auto-debit of Rs. {$chargeAmount} succeeded for {$mandate['application_no']} (Txn: {$cfPaymentId})"
        );

        $successCount++;
        $results[] = [
            'mandate_id'    => $mandateId,
            'finance_id'    => $financeId,
            'emi_id'        => $emiId,
            'amount'        => $chargeAmount,
            'cf_payment_id' => $cfPaymentId,
            'status'        => 'SUCCESS'
        ];

    } else {
        $errMsg = $chargeRes['message'] ?? 'Gateway debit failure';

        // Record failed attempt in mandate_debits
        $p->prepare("
            INSERT INTO mandate_debits (mandate_id, finance_id, emi_id, amount, status, failure_reason)
            VALUES (?, ?, ?, ?, 'FAILED', ?)
        ")->execute([
            $mandateId,
            $financeId,
            $emiId,
            $chargeAmount,
            $errMsg
        ]);

        $failureCount++;
        $results[] = [
            'mandate_id' => $mandateId,
            'finance_id' => $financeId,
            'emi_id'     => $emiId,
            'amount'     => $chargeAmount,
            'status'     => 'FAILED',
            'error'      => $errMsg
        ];
    }
}

$summary = [
    'date'            => $today,
    'total_active'    => count($activeMandates),
    'processed_count' => $processedCount,
    'success_count'   => $successCount,
    'failure_count'   => $failureCount,
    'results'         => $results
];

if ($isCli) {
    echo "========================================\n";
    echo "GO4FIN AUTOPAY RECURRING DEBIT RUNNER\n";
    echo "Date: {$today}\n";
    echo "Active Mandates: " . count($activeMandates) . "\n";
    echo "Processed: {$processedCount} | Succeeded: {$successCount} | Failed: {$failureCount}\n";
    echo "========================================\n";
    print_r($results);
} else {
    header('Content-Type: application/json');
    echo json_encode($summary, JSON_PRETTY_PRINT);
}
