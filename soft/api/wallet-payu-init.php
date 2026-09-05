<?php
require_once __DIR__ . '/../includes/auth.php';
auth();

$user = u();
$userId = (int)$user['id'];

// Get shop_id from POST or user's session or default to 1 (primary store)
$shopId = !empty($_POST['shop_id']) ? (int)$_POST['shop_id'] : (!empty($user['shop_id']) ? (int)$user['shop_id'] : 1);

$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
if ($amount < 10) {
    $redirectPath = ($user['role'] === 'superadmin') ? '/admin/wallet.php' : (($user['role'] === 'shop_admin') ? '/shop/wallet.php' : '/staff/wallet.php');
    header('Location: ' . url($redirectPath . '?msg=invalid_amount'));
    exit;
}

$amountFormatted = sprintf('%.2f', $amount);
$txnid = 'WAL' . time() . rand(100, 999);
$firstname = trim($user['name'] ?? 'User');
$email = trim($user['email'] ?? 'user@example.com');
$phone = trim($user['mobile'] ?? '9999999999');
$productinfo = 'Wallet Topup';

$udf1 = (string)$userId;
$udf2 = (string)$shopId;
$udf3 = (string)($user['role'] ?? 'admin');
$udf4 = '';
$udf5 = '';

// PayU Credentials from settings or constants
$payuKey  = get_setting('emi_payu_key') ?: PAYU_MERCHANT_KEY;
$payuSalt = get_setting('emi_payu_salt') ?: PAYU_SALT;
$payuEnv  = get_setting('emi_payu_env') ?: PAYU_ENV;
$payuBaseUrl = ($payuEnv === 'production') ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';

// Hash sequence for PayU: key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||salt
$hashString = $payuKey . '|' . $txnid . '|' . $amountFormatted . '|' . $productinfo . '|' . $firstname . '|' . $email . '|' . $udf1 . '|' . $udf2 . '|' . $udf3 . '|' . $udf4 . '|' . $udf5 . '||||||' . $payuSalt;
$hash = strtolower(hash('sha512', $hashString));

$surl = url('/api/wallet-payu-callback.php');
$furl = url('/api/wallet-payu-callback.php');

// Build full absolute callback URLs
if (!preg_match('/^https?:\/\//i', $surl)) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $surl = $scheme . $host . $surl;
    $furl = $scheme . $host . $furl;
}

// Store transaction in pending state
try {
    $stmt = db()->prepare("INSERT INTO wallet_transactions (user_id, shop_id, txnid, amount, type, status, payment_gateway, hash, remarks) VALUES (?, ?, ?, ?, 'credit', 'pending', 'PayU', ?, 'PayU Realtime Wallet Topup')");
    $stmt->execute([$userId, $shopId, $txnid, $amountFormatted, $hash]);
} catch (Exception $e) {
    die("Database error initializing payment: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Redirecting to PayU Payment Gateway...</title>
    <style>
        body {
            background-color: #0f172a;
            color: #f8fafc;
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .loader-card {
            background: rgba(30, 41, 59, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.4);
        }
        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(59, 130, 246, 0.2);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 1s infinite linear;
            margin: 0 auto 20px auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loader-card">
        <div class="spinner"></div>
        <h2>Redirecting to PayU Secure Payment Gateway</h2>
        <p style="color: #94a3b8; margin-top: 8px;">Please do not refresh or close this page. Topup Amount: ₹<?=number_format($amount, 2)?></p>
        
        <form id="payuForm" action="<?=$payuBaseUrl?>" method="POST">
            <input type="hidden" name="key" value="<?=$payuKey?>" />
            <input type="hidden" name="txnid" value="<?=$txnid?>" />
            <input type="hidden" name="amount" value="<?=$amountFormatted?>" />
            <input type="hidden" name="productinfo" value="<?=$productinfo?>" />
            <input type="hidden" name="firstname" value="<?=htmlspecialchars($firstname)?>" />
            <input type="hidden" name="email" value="<?=htmlspecialchars($email)?>" />
            <input type="hidden" name="phone" value="<?=htmlspecialchars($phone)?>" />
            <input type="hidden" name="surl" value="<?=htmlspecialchars($surl)?>" />
            <input type="hidden" name="furl" value="<?=htmlspecialchars($furl)?>" />
            <input type="hidden" name="udf1" value="<?=$udf1?>" />
            <input type="hidden" name="udf2" value="<?=$udf2?>" />
            <input type="hidden" name="udf3" value="<?=$udf3?>" />
            <input type="hidden" name="udf4" value="<?=$udf4?>" />
            <input type="hidden" name="udf5" value="<?=$udf5?>" />
            <input type="hidden" name="hash" value="<?=$hash?>" />
            <noscript>
                <button type="submit" style="padding: 12px 24px; background: #3b82f6; color:#fff; border:none; border-radius:8px; cursor:pointer; margin-top:20px;">Click here to Proceed to PayU</button>
            </noscript>
        </form>
    </div>

    <script>
        document.getElementById('payuForm').submit();
    </script>
</body>
</html>
