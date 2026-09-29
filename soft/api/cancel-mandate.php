<?php
/**
 * Backend Endpoint: Cancel Autopay / e-Mandate
 * 
 * Handles cancelling an active or pending Cashfree mandate:
 * 1. Authenticates customer or admin/staff
 * 2. Calls Cashfree PG API to cancel the subscription mandate on gateway
 * 3. Updates local DB record: status = 'CANCELLED', cancelled_at = NOW(), cancellation_reason
 * 4. Sends in-app customer notification reminding them to pay upcoming EMIs manually
 * 5. Logs audit trail in audit_logs
 * 6. Returns clean JSON (for AJAX) or redirects with flash message
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cashfree.php';

ensure_mandate_tables();

$user = u();
if (!$user) {
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login first.']);
        exit;
    }
    header("Location: " . url('/login.php'));
    exit;
}

$p = db();

// Accept mandate_id or finance_id via POST or GET
$mandateId = trim($_POST['mandate_id'] ?? ($_GET['mandate_id'] ?? ''));
$financeId = (int)($_POST['finance_id'] ?? ($_GET['finance_id'] ?? 0));
$reason    = trim($_POST['reason'] ?? ($_GET['reason'] ?? 'Cancelled by borrower'));

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || isset($_POST['ajax']);

// Find mandate
$mandate = null;
if (!empty($mandateId)) {
    $mStmt = $p->prepare("SELECT * FROM loan_mandates WHERE mandate_id = ? LIMIT 1");
    $mStmt->execute([$mandateId]);
    $mandate = $mStmt->fetch();
} elseif ($financeId > 0) {
    $mandate = get_latest_loan_mandate($financeId);
}

if (!$mandate) {
    if ($isAjax) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No mandate found for this loan.']);
        exit;
    }
    header("Location: " . url('/customer/autopay.php?err=' . urlencode('Mandate record not found.')));
    exit;
}

$financeId  = (int)$mandate['finance_id'];
$mandateId  = $mandate['mandate_id'];
$customerId = (int)$mandate['customer_id'];

// Authorization check: Customer can only cancel their own loan mandate
$cancelledBy = 'customer';
if ($user['role'] === 'customer') {
    $userEmail = $user['email'] ?? '';
    $userName = $user['name'] ?? '';
    $mobileFromEmail = str_replace('@customer.local', '', $userEmail);

    $sCust = $p->prepare('
        SELECT id FROM customers 
        WHERE (email != "" AND email = ?) 
           OR (mobile != "" AND (mobile = ? OR mobile = ?)) 
           OR name = ? 
        LIMIT 1
    ');
    $sCust->execute([$userEmail, $userEmail, $mobileFromEmail, $userName]);
    $loggedInCustId = (int)$sCust->fetchColumn();

    if ($loggedInCustId !== $customerId) {
        if ($isAjax) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: You cannot cancel another customer\'s mandate.']);
            exit;
        }
        die("Forbidden: Access denied.");
    }

    // Check if mandate is Non-Revocable under NPCI / RBI guidelines
    $isNonRevocable = isset($mandate['non_revocable']) ? (int)$mandate['non_revocable'] : ((isset($mandate['is_revocable']) && $mandate['is_revocable'] == 1) ? 0 : 1);
    if ($isNonRevocable === 1) {
        $nonRevMsg = 'This is a Non-Revocable loan mandate as per RBI & NPCI guidelines for loan repayments. Borrowers cannot unilaterally cancel active loan mandates. It automatically closes upon loan settlement.';
        if ($isAjax) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $nonRevMsg]);
            exit;
        }
        header("Location: " . url('/customer/autopay.php?finance_id=' . $financeId . '&err=' . urlencode($nonRevMsg)));
        exit;
    }
} else {
    $cancelledBy = $user['role'] ?: 'staff';
}

// Execute cancellation workflow
$result = cancel_loan_mandate($mandateId, $reason, $cancelledBy, (int)($user['id'] ?? 0));

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => $result['success'],
        'message'      => $result['message'],
        'mandate_id'   => $mandateId,
        'finance_id'   => $financeId,
        'status'       => 'CANCELLED',
        'cancelled_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Browser form redirect
$msgType = $result['success'] ? 'cancelled_success' : 'cancel_error';
$redirectUrl = url('/customer/autopay.php?finance_id=' . $financeId . '&msg=' . $msgType . '&notice=' . urlencode($result['message']));
header("Location: " . $redirectUrl);
exit;
