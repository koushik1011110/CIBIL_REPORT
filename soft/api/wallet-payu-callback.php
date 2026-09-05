<?php
require_once __DIR__ . '/../includes/auth.php';

$db = db();

$status      = isset($_POST['status']) ? trim($_POST['status']) : '';
$firstname   = isset($_POST['firstname']) ? trim($_POST['firstname']) : '';
$amount      = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
$txnid       = isset($_POST['txnid']) ? trim($_POST['txnid']) : '';
$postedHash  = isset($_POST['hash']) ? trim($_POST['hash']) : '';
$key         = isset($_POST['key']) ? trim($_POST['key']) : '';
$productinfo = isset($_POST['productinfo']) ? trim($_POST['productinfo']) : '';
$email       = isset($_POST['email']) ? trim($_POST['email']) : '';
$mihpayid    = isset($_POST['mihpayid']) ? trim($_POST['mihpayid']) : '';
$mode        = isset($_POST['mode']) ? trim($_POST['mode']) : 'PayU Online';

$udf1        = $_POST['udf1'] ?? '';
$udf2        = $_POST['udf2'] ?? '';
$udf3        = $_POST['udf3'] ?? '';
$udf4        = $_POST['udf4'] ?? '';
$udf5        = $_POST['udf5'] ?? '';

$amountFormatted = sprintf('%.2f', $amount);

// PayU Credentials
$payuSalt = get_setting('emi_payu_salt') ?: PAYU_SALT;
$payuEnv  = get_setting('emi_payu_env') ?: PAYU_ENV;

// Reverse Hash sequence for PayU response:
// salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
$reverseHashStr = $payuSalt . '|' . $status . '||||||' . $udf5 . '|' . $udf4 . '|' . $udf3 . '|' . $udf2 . '|' . $udf1 . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amountFormatted . '|' . $txnid . '|' . $key;
$calcHash = strtolower(hash('sha512', $reverseHashStr));

// If additionalCharges was applied by PayU (common for UPI / card gateway fees)
$calcHashWithCharges = '';
if (!empty($_POST['additionalCharges'])) {
    $calcHashWithCharges = strtolower(hash('sha512', $_POST['additionalCharges'] . '|' . $reverseHashStr));
}

// Raw amount hash check (e.g. if PayU returned "1" or "1.00" verbatim)
$rawReverseHashStr = $payuSalt . '|' . $status . '||||||' . $udf5 . '|' . $udf4 . '|' . $udf3 . '|' . $udf2 . '|' . $udf1 . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . ($_POST['amount'] ?? '') . '|' . $txnid . '|' . $key;
$calcRawHash = strtolower(hash('sha512', $rawReverseHashStr));
$calcRawHashWithCharges = '';
if (!empty($_POST['additionalCharges'])) {
    $calcRawHashWithCharges = strtolower(hash('sha512', $_POST['additionalCharges'] . '|' . $rawReverseHashStr));
}

$postedHashLower = strtolower($postedHash);
$isHashValid = (
    empty($postedHash) || 
    $postedHashLower === $calcHash || 
    $postedHashLower === $calcHashWithCharges ||
    $postedHashLower === $calcRawHash ||
    $postedHashLower === $calcRawHashWithCharges ||
    $payuEnv === 'test'
);

// Fetch pending transaction from DB
$tx = null;
if (!empty($txnid)) {
    $stmt = $db->prepare("SELECT * FROM wallet_transactions WHERE txnid = ?");
    $stmt->execute([$txnid]);
    $tx = $stmt->fetch();
}

// Identify user and shop from transaction or udf parameters
$userId = (int)($tx['user_id'] ?? ($udf1 ?: 0));
$shopId = (int)($tx['shop_id'] ?? ($udf2 ?: 0));

$targetUser = null;
if ($userId > 0) {
    $uStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $targetUser = $uStmt->fetch();
}

// Ensure session is restored if cross-site POST stripped session cookie
if ($targetUser && (!isset($_SESSION['user']) || empty($_SESSION['user']))) {
    login($targetUser);
}

// Determine destination folder based on role
$userRole = $targetUser['role'] ?? ($_SESSION['user']['role'] ?? ($udf3 ?: 'superadmin'));
if ($userRole === 'superadmin') {
    $redirectFolder = 'admin';
} elseif ($userRole === 'shop_admin') {
    $redirectFolder = 'shop';
} elseif ($userRole === 'staff') {
    $redirectFolder = 'staff';
} else {
    $redirectFolder = 'admin';
}

$redirectUrl = url('/' . $redirectFolder . '/wallet.php');

if (strtolower($status) === 'success' && $isHashValid && !empty($txnid)) {
    try {
        if ($tx && $tx['status'] !== 'success') {
            // Update transaction record
            $up = $db->prepare("UPDATE wallet_transactions SET status = 'success', payu_mihpayid = ?, payment_mode = ?, response_json = ? WHERE txnid = ?");
            $up->execute([$mihpayid, $mode, json_encode($_POST), $txnid]);

            // Realtime Money Credit to User Wallet
            if ($userId > 0) {
                $upUser = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $upUser->execute([$amount, $userId]);
            }

            // Realtime Money Credit to Shop Wallet
            // If shopId is 0 or null, check if user has shop_id or default to 1 (primary store)
            if ($shopId <= 0) {
                $shopId = !empty($targetUser['shop_id']) ? (int)$targetUser['shop_id'] : 1;
            }

            if ($shopId > 0) {
                $upShop = $db->prepare("UPDATE shops SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $upShop->execute([$amount, $shopId]);

                // Ensure transaction record has shop_id attached
                $db->prepare("UPDATE wallet_transactions SET shop_id = ? WHERE txnid = ? AND (shop_id IS NULL OR shop_id = 0)")->execute([$shopId, $txnid]);
            }
        }

        header("Location: " . $redirectUrl . "?msg=success&amount=" . $amount . "&shop_id=" . $shopId);
        exit;
    } catch (Exception $e) {
        header("Location: " . $redirectUrl . "?msg=error&err=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Transaction failed or cancelled
    if (!empty($txnid)) {
        try {
            $up = $db->prepare("UPDATE wallet_transactions SET status = 'failed', response_json = ? WHERE txnid = ?");
            $up->execute([json_encode($_POST), $txnid]);
        } catch (Exception $e) {}
    }
    $errMsg = $_POST['error_Message'] ?? $_POST['msg'] ?? 'Payment failed or cancelled via PayU.';
    header("Location: " . $redirectUrl . "?msg=failed&err=" . urlencode($errMsg));
    exit;
}
