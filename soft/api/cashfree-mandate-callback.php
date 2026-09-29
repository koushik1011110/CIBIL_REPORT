<?php
/**
 * Cashfree Customer Mandate Return Callback
 * Handles customer return after Cashfree Subscriptions authorization,
 * verifies mandate status with Cashfree API, activates mandate in DB,
 * and redirects to the Autopay portal.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';

ensure_mandate_tables();

$mandateId = trim($_GET['mandate_id'] ?? ($_GET['subscription_id'] ?? ($_POST['mandate_id'] ?? '')));
$financeId = (int)($_GET['finance_id'] ?? 0);

if (empty($mandateId)) {
    die("Invalid access: No mandate ID provided.");
}

$p = db();

// Lookup mandate record in database
$stmt = $p->prepare("SELECT * FROM loan_mandates WHERE mandate_id = ? LIMIT 1");
$stmt->execute([$mandateId]);
$mandate = $stmt->fetch();

if ($mandate) {
    $financeId = (int)$mandate['finance_id'];
    $customerId = (int)$mandate['customer_id'];
}

// Server-to-server verification with Cashfree Subscriptions API
$cfRes = cashfree_get_subscription($mandateId);

if (!$cfRes['success']) {
    $errMsg = $cfRes['message'] ?? 'Could not verify subscription with Cashfree.';
    $redirectUrl = url('/customer/autopay.php' . ($financeId > 0 ? '?finance_id=' . $financeId : ''));
    header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') !== false ? '&' : '?') . "mandate=error&err=" . urlencode($errMsg));
    exit;
}

$subData   = $cfRes['data'] ?? [];
$subStatus = strtoupper(trim($subData['subscription_status'] ?? ''));
$cfSubId   = $subData['cf_subscription_id'] ?? ($mandate['cf_subscription_id'] ?? '');

// Determine payment method / authorization mode
$authDetails = $subData['authorization_details'] ?? [];
$authMode = 'UPI / e-NACH';
if (!empty($authDetails['payment_method'])) {
    $authMode = strtoupper($authDetails['payment_method']);
}

// Determine next scheduled debit date
$nextSchedule = $subData['next_schedule_date'] ?? null;
$nextDebitSql = null;
if (!empty($nextSchedule)) {
    $nextDebitSql = date('Y-m-d H:i:s', strtotime($nextSchedule));
} elseif ($financeId > 0) {
    $nextDueStmt = $p->prepare("SELECT due_date FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC LIMIT 1");
    $nextDueStmt->execute([$financeId]);
    $nextDue = $nextDueStmt->fetchColumn();
    if ($nextDue) {
        $nextDebitSql = date('Y-m-d 10:00:00', strtotime($nextDue));
    }
}

// Success or Pending approval statuses
if (in_array($subStatus, ['ACTIVE', 'BANK_APPROVAL_PENDING', 'INITIALIZED'])) {
    $targetStatus = ($subStatus === 'BANK_APPROVAL_PENDING') ? 'BANK_APPROVAL_PENDING' : 'ACTIVE';

    if ($mandate) {
        $p->prepare("
            UPDATE loan_mandates 
            SET status = ?, 
                cf_subscription_id = ?, 
                auth_mode = ?, 
                next_debit_date = COALESCE(?, next_debit_date),
                response_json = ? 
            WHERE id = ?
        ")->execute([
            $targetStatus,
            $cfSubId,
            $authMode,
            $nextDebitSql,
            json_encode($subData),
            $mandate['id']
        ]);
    }

    // Lookup application for notifications and audit
    $appNo = "Loan #{$financeId}";
    if ($financeId > 0) {
        $appStmt = $p->prepare("SELECT application_no FROM finance_applications WHERE id = ?");
        $appStmt->execute([$financeId]);
        $appNo = $appStmt->fetchColumn() ?: $appNo;
    }

    // Customer Notification
    if (!empty($customerId)) {
        try {
            $userStmt = $p->prepare("SELECT u.id FROM users u 
                JOIN customers c ON (c.email = u.email OR c.mobile = u.email OR u.name = c.name) 
                WHERE c.id = ? LIMIT 1");
            $userStmt->execute([$customerId]);
            $targetUserId = $userStmt->fetchColumn();

            if ($targetUserId) {
                $statusMsg = ($targetStatus === 'BANK_APPROVAL_PENDING')
                    ? "Your e-Mandate authorization for {$appNo} has been submitted and is pending bank approval."
                    : "✓ Your Autopay e-Mandate for {$appNo} is now ACTIVE! Monthly EMIs will be automatically debited on due date.";

                $p->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())")
                  ->execute([$targetUserId, 'Autopay e-Mandate Activated', $statusMsg]);
            }
        } catch (Exception $ex) {}
    }

    // System Audit Log
    try {
        log_audit(
            'Autopay Mandate Activated',
            'Payments',
            "Autopay e-Mandate {$mandateId} activated for {$appNo} via {$authMode}."
        );
    } catch (Exception $ex) {}

    $rHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $rScheme = (in_array($rHost, ['localhost', '127.0.0.1'])) ? 'http://' : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');
    $redirectUrl = $rScheme . $rHost . url('/customer/autopay.php?finance_id=' . $financeId . '&mandate=success&status=' . urlencode($targetStatus));
    header("Location: " . $redirectUrl);
    exit;

} else {
    // Mandate Failed or Cancelled by customer on Cashfree checkout
    if ($mandate) {
        $p->prepare("UPDATE loan_mandates SET status = ?, response_json = ? WHERE id = ?")
          ->execute([$subStatus ?: 'FAILED', json_encode($subData), $mandate['id']]);
    }

    $rHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $rScheme = (in_array($rHost, ['localhost', '127.0.0.1'])) ? 'http://' : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');
    $redirectUrl = $rScheme . $rHost . url('/customer/autopay.php?finance_id=' . $financeId . '&mandate=failed&status=' . urlencode($subStatus));
    header("Location: " . $redirectUrl);
    exit;
}
