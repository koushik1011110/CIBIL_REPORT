<?php
require_once __DIR__ . '/../includes/auth.php';
auth();

$user = u();
$financeId = isset($_REQUEST['finance_id']) ? (int)$_REQUEST['finance_id'] : 0;
$emiId = isset($_REQUEST['emi_id']) ? (int)$_REQUEST['emi_id'] : 0;

$p = db();
$stmt = $p->prepare('SELECT f.*, c.name as customer_name, c.email as customer_email, c.mobile as customer_mobile FROM finance_applications f JOIN customers c ON c.id = f.customer_id WHERE f.id = ?');
$stmt->execute([$financeId]);
$app = $stmt->fetch();

if (!$app) {
    die("Finance Application not found.");
}

$isDownPayment = (isset($_REQUEST['is_downpayment']) && $_REQUEST['is_downpayment'] == 1) || ($app['status'] === 'pending' && $emiId === 0);
$isFullForeclosure = (isset($_REQUEST['emi_id']) && $_REQUEST['emi_id'] === 'full');

$emi = null;
if ($isFullForeclosure) {
    $sumStmt = $p->prepare("SELECT SUM(amount) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
    $sumStmt->execute([$financeId]);
    $amount = floatval($sumStmt->fetchColumn() ?: 0);
    $productinfo = 'Full Loan Foreclosure Settlement App ' . $app['application_no'];
    $txnid = 'FCL' . time() . rand(100, 999);
} else if (!$isDownPayment) {
    if ($emiId > 0) {
        $eStmt = $p->prepare('SELECT * FROM emi_schedules WHERE id = ? AND finance_id = ?');
        $eStmt->execute([$emiId, $financeId]);
        $emi = $eStmt->fetch();
    } else {
        $eStmt = $p->prepare('SELECT * FROM emi_schedules WHERE finance_id = ? AND status != "paid" ORDER BY installment_no ASC LIMIT 1');
        $eStmt->execute([$financeId]);
        $emi = $eStmt->fetch();
    }
}

if ($isFullForeclosure) {
    // Amount already calculated above
} else if ($isDownPayment) {
    $amount = floatval($app['down_payment']);
    if ($amount <= 0) {
        $amount = floatval($app['emi']);
    }
    $productinfo = 'Down Payment for Loan App ' . $app['application_no'];
    $txnid = 'DP' . time() . rand(100, 999);
} else {
    $amount = $emi ? floatval($emi['amount']) : floatval($app['emi']);
    $productinfo = 'EMI Payment ' . $app['application_no'] . ($emi ? ' Installment #' . $emi['installment_no'] : '');
    $txnid = 'EMI' . time() . rand(100, 999);
}

if ($amount <= 0) {
    die("Invalid payment amount.");
}

$amountFormatted = sprintf('%.2f', $amount);
$firstname = trim($app['customer_name'] ?? 'Customer');
$email = trim($app['customer_email'] ?: ($user['email'] ?? 'customer@example.com'));
$phone = trim($app['customer_mobile'] ?: '9999999999');

$udf1 = (string)$financeId;
$udf2 = (string)($emi ? $emi['id'] : 0);
$udf3 = (string)$app['customer_id'];
$udf4 = (string)($app['shop_id'] ?? 1);
$udf5 = '';

$emiKey  = get_setting('emi_payu_key') ?: PAYU_MERCHANT_KEY;
$emiSalt = get_setting('emi_payu_salt') ?: PAYU_SALT;
$emiEnv  = get_setting('emi_payu_env') ?: PAYU_ENV;
$payuBaseUrl = ($emiEnv === 'production') ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';

// Hash sequence for PayU: key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||salt
$hashString = $emiKey . '|' . $txnid . '|' . $amountFormatted . '|' . $productinfo . '|' . $firstname . '|' . $email . '|' . $udf1 . '|' . $udf2 . '|' . $udf3 . '|' . $udf4 . '|' . $udf5 . '||||||' . $emiSalt;
$hash = strtolower(hash('sha512', $hashString));

$surl = url('/api/pay-emi-callback.php');
$furl = url('/api/pay-emi-callback.php');

if (!preg_match('/^https?:\/\//i', $surl)) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $surl = $scheme . $host . $surl;
    $furl = $scheme . $host . $furl;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Redirecting to PayU for EMI Payment...</title>
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
        <p style="color: #94a3b8; margin-top: 8px;">Application: <strong><?=e($app['application_no'])?></strong> | Amount: <strong>₹<?=number_format($amount, 2)?></strong></p>
        
        <form id="payuForm" action="<?=$payuBaseUrl?>" method="POST">
            <input type="hidden" name="key" value="<?=$emiKey?>" />
            <input type="hidden" name="txnid" value="<?=$txnid?>" />
            <input type="hidden" name="amount" value="<?=$amountFormatted?>" />
            <input type="hidden" name="productinfo" value="<?=htmlspecialchars($productinfo)?>" />
            <input type="hidden" name="firstname" value="<?=htmlspecialchars($firstname)?>" />
            <input type="hidden" name="email" value="<?=htmlspecialchars($email)?>" />
            <input type="hidden" name="phone" value="<?=htmlspecialchars($phone)?>" />
            <input type="hidden" name="surl" value="<?=htmlspecialchars($surl)?>" />
            <input type="hidden" name="furl" value="<?=htmlspecialchars($furl)?>" />
            <input type="hidden" name="udf1" value="<?=$udf1?>" />
            <input type="hidden" name="udf2" value="<?=$udf2?>" />
            <input type="hidden" name="udf3" value="<?=$udf3?>" />
            <input type="hidden" name="udf4" value="<?=$udf4?>" />
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
