<?php
/**
 * Cashfree Payments Helper (PG API v2023-08-01)
 * Handles order creation, payment session fetching, and payment status verification.
 */

require_once __DIR__ . '/../config/config.php';

function cashfree_get_config()
{
    $appId     = trim(get_setting('emi_cashfree_app_id') ?: get_setting('cashfree_app_id', ''));
    $secretKey = trim(get_setting('emi_cashfree_secret_key') ?: get_setting('cashfree_secret_key', ''));
    $env       = strtolower(trim(get_setting('emi_cashfree_env') ?: get_setting('cashfree_env', 'sandbox')));

    if ($env !== 'production') {
        $env = 'sandbox';
    }

    $baseUrl = ($env === 'production')
        ? 'https://api.cashfree.com/pg'
        : 'https://sandbox.cashfree.com/pg';

    return [
        'app_id'      => $appId,
        'secret_key'  => $secretKey,
        'env'         => $env,
        'base_url'    => $baseUrl,
        'api_version' => '2023-08-01'
    ];
}

function cashfree_is_configured()
{
    $cfg = cashfree_get_config();
    return !empty($cfg['app_id']) && !empty($cfg['secret_key']);
}

function cashfree_api_request($endpoint, $method = 'GET', $data = null)
{
    $cfg = cashfree_get_config();
    if (empty($cfg['app_id']) || empty($cfg['secret_key'])) {
        return [
            'success' => false,
            'message' => 'Cashfree credentials (App ID / Secret Key) are not configured in Admin Settings.'
        ];
    }

    $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($endpoint, '/');

    $headers = [
        'x-client-id: ' . $cfg['app_id'],
        'x-client-secret: ' . $cfg['secret_key'],
        'x-api-version: ' . $cfg['api_version'],
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
        }
    } elseif (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'success'   => false,
            'http_code' => $httpCode,
            'message'   => 'Cashfree cURL Error: ' . $curlErr
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'success'   => false,
            'http_code' => $httpCode,
            'raw'       => $response,
            'message'   => 'Invalid response received from Cashfree.'
        ];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success'   => true,
            'http_code' => $httpCode,
            'data'      => $decoded
        ];
    }

    $errMsg = $decoded['message'] ?? ($decoded['error'] ?? 'Cashfree API Error (' . $httpCode . ')');
    return [
        'success'   => false,
        'http_code' => $httpCode,
        'data'      => $decoded,
        'message'   => $errMsg
    ];
}

/**
 * Create a new Cashfree PG Order
 */
function cashfree_create_order($orderData)
{
    $res = cashfree_api_request('orders', 'POST', $orderData);
    if ($res['success']) {
        return [
            'success'            => true,
            'order_id'           => $res['data']['order_id'] ?? '',
            'cf_order_id'        => $res['data']['cf_order_id'] ?? '',
            'payment_session_id' => $res['data']['payment_session_id'] ?? '',
            'order_status'       => $res['data']['order_status'] ?? '',
            'data'               => $res['data']
        ];
    }
    return $res;
}

/**
 * Fetch Order details from Cashfree
 */
function cashfree_get_order($orderId)
{
    return cashfree_api_request('orders/' . urlencode($orderId), 'GET');
}

/**
 * Fetch Payment attempts for an Order from Cashfree
 */
function cashfree_get_order_payments($orderId)
{
    return cashfree_api_request('orders/' . urlencode($orderId) . '/payments', 'GET');
}

// ==============================================================================
// CASHFREE AUTOPAY / E-MANDATE / SUBSCRIPTION SUITE
// ==============================================================================

/**
 * Ensures loan_mandates and mandate_debits tables exist in the database
 */
function ensure_mandate_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $p = db();
        $p->exec("CREATE TABLE IF NOT EXISTS loan_mandates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mandate_id VARCHAR(100) NOT NULL UNIQUE,
            cf_subscription_id VARCHAR(100) NULL,
            subscription_session_id VARCHAR(255) NULL,
            finance_id INT NOT NULL,
            customer_id INT NOT NULL,
            plan_name VARCHAR(150) NULL,
            amount DECIMAL(12,2) NOT NULL,
            max_amount DECIMAL(12,2) NOT NULL,
            interval_type VARCHAR(20) DEFAULT 'MONTH',
            intervals INT DEFAULT 1,
            max_cycles INT DEFAULT 12,
            auth_mode VARCHAR(60) DEFAULT 'UPI / e-NACH / Card',
            is_revocable TINYINT(1) DEFAULT 0,
            non_revocable TINYINT(1) DEFAULT 1,
            status VARCHAR(30) DEFAULT 'PENDING',
            cancellation_reason TEXT NULL,
            cancelled_by VARCHAR(50) NULL,
            cancelled_at DATETIME NULL,
            last_debit_date DATETIME NULL,
            next_debit_date DATETIME NULL,
            response_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_lm_fin (finance_id),
            INDEX idx_lm_cust (customer_id),
            INDEX idx_lm_stat (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Ensure is_revocable and non_revocable columns exist if table was already created
        $chkCol1 = $p->query("SHOW COLUMNS FROM loan_mandates LIKE 'is_revocable'")->fetch();
        if (!$chkCol1) {
            $p->exec("ALTER TABLE loan_mandates ADD COLUMN is_revocable TINYINT(1) DEFAULT 0 AFTER auth_mode");
        }
        $chkCol2 = $p->query("SHOW COLUMNS FROM loan_mandates LIKE 'non_revocable'")->fetch();
        if (!$chkCol2) {
            $p->exec("ALTER TABLE loan_mandates ADD COLUMN non_revocable TINYINT(1) DEFAULT 1 AFTER is_revocable");
        }

        $p->exec("CREATE TABLE IF NOT EXISTS mandate_debits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mandate_id VARCHAR(100) NOT NULL,
            finance_id INT NOT NULL,
            emi_id INT NULL,
            cf_payment_id VARCHAR(100) NULL,
            amount DECIMAL(12,2) NOT NULL,
            status VARCHAR(30) DEFAULT 'PENDING',
            failure_reason TEXT NULL,
            scheduled_date DATE NULL,
            debited_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_md_man (mandate_id),
            INDEX idx_md_fin (finance_id),
            INDEX idx_md_emi (emi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log("ensure_mandate_tables error: " . $e->getMessage());
    }
}

/**
 * Create a new Cashfree Subscription (Autopay / Mandate)
 */
function cashfree_create_subscription($subData)
{
    ensure_mandate_tables();
    $res = cashfree_api_request('subscriptions', 'POST', $subData);
    if ($res['success']) {
        return [
            'success'                 => true,
            'subscription_id'         => $res['data']['subscription_id'] ?? ($subData['subscription_id'] ?? ''),
            'cf_subscription_id'      => $res['data']['cf_subscription_id'] ?? '',
            'subscription_session_id' => $res['data']['subscription_session_id'] ?? '',
            'subscription_status'     => $res['data']['subscription_status'] ?? '',
            'data'                    => $res['data']
        ];
    }
    return $res;
}

/**
 * Fetch Subscription (Mandate) details from Cashfree
 */
function cashfree_get_subscription($subId)
{
    ensure_mandate_tables();
    return cashfree_api_request('subscriptions/' . urlencode($subId), 'GET');
}

/**
 * Manage Subscription (Action: CANCEL, PAUSE, ACTIVATE)
 */
function cashfree_manage_subscription($subId, $action = 'CANCEL')
{
    ensure_mandate_tables();
    return cashfree_api_request('subscriptions/' . urlencode($subId) . '/manage', 'POST', [
        'action' => strtoupper($action)
    ]);
}

/**
 * Charge an active Subscription on-demand / scheduled
 */
function cashfree_charge_subscription($chargeData)
{
    ensure_mandate_tables();
    return cashfree_api_request('subscriptions/pay', 'POST', $chargeData);
}

/**
 * Retrieve the active or pending mandate for a loan
 */
function get_active_loan_mandate($financeId)
{
    ensure_mandate_tables();
    $p = db();
    $s = $p->prepare("SELECT * FROM loan_mandates WHERE finance_id = ? AND status IN ('ACTIVE', 'PENDING', 'INITIALIZED', 'BANK_APPROVAL_PENDING') ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$financeId]);
    return $s->fetch() ?: null;
}

/**
 * Retrieve latest mandate for a loan regardless of status
 */
function get_latest_loan_mandate($financeId)
{
    ensure_mandate_tables();
    $p = db();
    $s = $p->prepare("SELECT * FROM loan_mandates WHERE finance_id = ? ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$financeId]);
    return $s->fetch() ?: null;
}

/**
 * Complete Backend Cancellation Workflow for an Autopay Mandate:
 * 1. Verifies mandate state in database
 * 2. Calls Cashfree PG API to cancel the subscription on gateway
 * 3. Updates loan_mandates table with status = 'CANCELLED', cancelled_at = NOW(), reason & actor
 * 4. Inserts customer system notification
 * 5. Records entry in audit_logs
 *
 * @param string|int $mandateIdOrFinanceId Mandate ID (e.g. SUB_...) or Finance Application ID
 * @param string $reason Cancellation reason
 * @param string $cancelledBy 'customer', 'admin', 'staff', or 'system'
 * @param int|null $actorUserId ID of user executing cancellation
 * @return array ['success' => bool, 'message' => string, 'data' => array]
 */
function cancel_loan_mandate($mandateIdOrFinanceId, $reason = 'Requested by user', $cancelledBy = 'customer', $actorUserId = null)
{
    ensure_mandate_tables();
    $p = db();

    // Find mandate record
    if (is_numeric($mandateIdOrFinanceId)) {
        $stmt = $p->prepare("SELECT * FROM loan_mandates WHERE finance_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$mandateIdOrFinanceId]);
    } else {
        $stmt = $p->prepare("SELECT * FROM loan_mandates WHERE mandate_id = ? LIMIT 1");
        $stmt->execute([trim($mandateIdOrFinanceId)]);
    }
    $mandate = $stmt->fetch();

    if (!$mandate) {
        return [
            'success' => false,
            'message' => 'Mandate record not found.'
        ];
    }

    $mandateId = $mandate['mandate_id'];
    $financeId = (int)$mandate['finance_id'];
    $customerId = (int)$mandate['customer_id'];

    if ($mandate['status'] === 'CANCELLED') {
        return [
            'success' => true,
            'already_cancelled' => true,
            'message' => 'Autopay mandate is already cancelled.',
            'mandate' => $mandate
        ];
    }

    // Non-revocable mandate check: borrowers cannot revoke active loan/EMI mandates
    $isNonRevocable = isset($mandate['non_revocable']) ? (int)$mandate['non_revocable'] : ((isset($mandate['is_revocable']) && $mandate['is_revocable'] == 1) ? 0 : 1);
    if ($cancelledBy === 'customer' && $isNonRevocable === 1) {
        return [
            'success' => false,
            'message' => 'This is a non-revocable loan mandate under NPCI & RBI guidelines for loan repayments. Borrowers cannot unilaterally cancel active loan mandates. It automatically terminates upon complete loan repayment or lender closure.'
        ];
    }

    // 1. Call Cashfree PG Manage API to cancel mandate on gateway
    $cfRes = cashfree_manage_subscription($mandateId, 'CANCEL');
    $cfCancelled = false;
    $cfErrMsg = '';

    if ($cfRes['success']) {
        $cfCancelled = true;
    } else {
        $errMsg = strtolower($cfRes['message'] ?? '');
        // If it's already cancelled or expired on Cashfree side, consider it successful
        if (strpos($errMsg, 'already cancelled') !== false || strpos($errMsg, 'inactive') !== false || strpos($errMsg, 'not found') !== false) {
            $cfCancelled = true;
        } else {
            $cfErrMsg = $cfRes['message'] ?? 'Gateway returned an error.';
        }
    }

    // 2. Update Database Record
    $cleanReason = trim($reason) ?: 'Cancelled by ' . ucfirst($cancelledBy);
    $upStmt = $p->prepare("UPDATE loan_mandates 
        SET status = 'CANCELLED', 
            cancellation_reason = ?, 
            cancelled_by = ?, 
            cancelled_at = NOW(),
            response_json = ? 
        WHERE id = ?");
    $upStmt->execute([
        $cleanReason,
        $cancelledBy . ($actorUserId ? " (#{$actorUserId})" : ""),
        !empty($cfRes['data']) ? json_encode($cfRes['data']) : $mandate['response_json'],
        $mandate['id']
    ]);

    // 3. Look up Application info
    $appStmt = $p->prepare("SELECT application_no FROM finance_applications WHERE id = ?");
    $appStmt->execute([$financeId]);
    $appNo = $appStmt->fetchColumn() ?: "Loan #{$financeId}";

    // 4. Send Customer Notification
    try {
        $userStmt = $p->prepare("SELECT u.id FROM users u 
            JOIN customers c ON (c.email = u.email OR c.mobile = u.email OR u.name = c.name) 
            WHERE c.id = ? LIMIT 1");
        $userStmt->execute([$customerId]);
        $targetUserId = $userStmt->fetchColumn();

        if ($targetUserId) {
            $p->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())")
              ->execute([
                  $targetUserId,
                  'Autopay Mandate Cancelled',
                  "The monthly auto-debit mandate for {$appNo} has been cancelled. Please make upcoming EMI payments manually via Cashfree / UPI."
              ]);
        }
    } catch (Exception $ex) {
        error_log("Mandate cancellation notification error: " . $ex->getMessage());
    }

    // 5. Write to System Audit Log
    try {
        log_audit(
            'Autopay Mandate Cancelled',
            'Payments',
            "Autopay Mandate {$mandateId} for {$appNo} (EMI: Rs. {$mandate['amount']}) was cancelled by {$cancelledBy}. Reason: {$cleanReason}",
            $actorUserId
        );
    } catch (Exception $ex) {
        error_log("Mandate audit log error: " . $ex->getMessage());
    }

    return [
        'success' => true,
        'message' => 'Autopay mandate cancelled successfully. Auto-debit has been disabled for this loan.',
        'mandate_id' => $mandateId,
        'finance_id' => $financeId,
        'gateway_synced' => $cfCancelled,
        'gateway_notice' => $cfErrMsg ?: null
    ];
}

