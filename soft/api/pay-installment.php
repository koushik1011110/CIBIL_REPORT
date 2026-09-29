<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cashfree.php';
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
    $productinfo = 'Full Loan Settlement ' . $app['application_no'];
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
    $productinfo = 'Down Payment App ' . $app['application_no'];
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

$activeGateway = get_setting('emi_active_gateway', 'cashfree');

// ==============================================================================
// 1. CASHFREE PAYMENT GATEWAY INTEGRATION (PRIMARY / DEFAULT)
// ==============================================================================
if ($activeGateway === 'cashfree') {
    $cfCfg = cashfree_get_config();

    if (!cashfree_is_configured()) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Gateway Setup Required - GO4 Finance</title>
            <style>
                body { background-color: #0f172a; color: #f8fafc; font-family: 'Segoe UI', system-ui, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
                .card { background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 32px; max-width: 480px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
                .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size: 40px; margin-bottom: 12px;">⚙️</div>
                <h2 style="color: #f87171; margin: 0 0 10px 0;">Cashfree Gateway Configuration Needed</h2>
                <p style="color: #94a3b8; font-size: 14px; line-height: 1.6;">The administrator has not configured the Cashfree App ID and Secret Key yet in System Settings.</p>
                <?php if (in_array($user['role'], ['superadmin'])): ?>
                    <a href="<?=url('/admin/settings.php')?>" class="btn" style="background: #3b82f6; color: #fff;">⚙️ Configure Cashfree in Settings</a>
                <?php else: ?>
                    <a href="<?=url('/customer/emi-schedule.php?finance_id=' . $financeId)?>" class="btn" style="background: #334155; color: #cbd5e1;">← Back to EMI Schedule</a>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Clean Phone number (Cashfree requires 10 digit number)
    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (strlen($cleanPhone) > 10) {
        $cleanPhone = substr($cleanPhone, -10);
    }
    if (strlen($cleanPhone) < 10) {
        $cleanPhone = '9999999999';
    }

    // Clean Customer Name
    $cleanName = preg_replace('/[^a-zA-Z0-9\s]/', '', $firstname);
    if (empty($cleanName)) {
        $cleanName = 'Customer';
    }

    // Unique Order ID (max 45 chars, alphanumeric with _ or -)
    $tag = $emi ? 'EMI' . $emi['id'] : ($isDownPayment ? 'DP' : 'FCL');
    $orderId = 'CF_' . $financeId . '_' . $tag . '_' . time() . rand(10, 99);
    if (strlen($orderId) > 44) {
        $orderId = substr($orderId, 0, 44);
    }

    // Cashfree PG strictly requires HTTPS scheme for return_url
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $customAppUrl = trim(get_setting('app_url', ''));
    if (!empty($customAppUrl)) {
        $baseUrlWithScheme = rtrim($customAppUrl, '/');
    } else {
        $baseUrlWithScheme = 'https://' . $host;
    }

    $returnUrl = $baseUrlWithScheme . url('/api/cashfree-emi-callback.php?order_id={order_id}');
    $orderMeta = [
        'return_url' => $returnUrl
    ];

    // Include notify_url if on public host or custom URL provided
    $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1']) || preg_match('/^192\.168\./', $host);
    if (!empty($customAppUrl) || !$isLocalhost) {
        $orderMeta['notify_url'] = $baseUrlWithScheme . url('/api/cashfree-emi-webhook.php');
    }

    $cfOrderPayload = [
        'order_id'         => $orderId,
        'order_amount'     => round($amount, 2),
        'order_currency'   => 'INR',
        'customer_details' => [
            'customer_id'    => 'cust_' . (int)$app['customer_id'],
            'customer_name'  => substr($cleanName, 0, 50),
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'customer@go4fin.com',
            'customer_phone' => $cleanPhone
        ],
        'order_meta' => $orderMeta,
        'order_note' => substr($productinfo, 0, 100),
        'order_tags' => [
            'finance_id'  => (string)$financeId,
            'emi_id'      => (string)($emi ? $emi['id'] : 0),
            'customer_id' => (string)$app['customer_id']
        ]
    ];

    $createRes = cashfree_create_order($cfOrderPayload);

    if (!$createRes['success'] || empty($createRes['payment_session_id'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Payment Initiation Failed - GO4 Finance</title>
            <style>
                body { background-color: #0f172a; color: #f8fafc; font-family: 'Segoe UI', system-ui, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
                .card { background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 32px; max-width: 500px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
                .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size: 40px; margin-bottom: 12px;">⚠️</div>
                <h2 style="color: #f87171; margin: 0 0 10px 0;">Payment Initiation Failed</h2>
                <p style="color: #cbd5e1; font-size: 14px;"><?=htmlspecialchars($createRes['message'] ?? 'Unable to connect to Cashfree Payment Gateway.')?></p>
                <a href="<?=url('/customer/emi-schedule.php?finance_id=' . $financeId)?>" class="btn" style="background: #334155; color: #cbd5e1;">← Return to EMI Schedule</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    $paymentSessionId = $createRes['payment_session_id'];
    $cfOrderId        = $createRes['cf_order_id'] ?? '';

    // Record order in gateway_orders
    $stmtIns = $p->prepare("INSERT INTO gateway_orders (order_id, cf_order_id, finance_id, emi_id, customer_id, amount, gateway, payment_session_id, status) VALUES (?, ?, ?, ?, ?, ?, 'cashfree', ?, 'PENDING')");
    $stmtIns->execute([
        $orderId,
        $cfOrderId,
        $financeId,
        $emi ? $emi['id'] : null,
        $app['customer_id'],
        $amount,
        $paymentSessionId
    ]);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Connecting to Cashfree Secure Payments...</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: radial-gradient(circle at 50% 20%, #1e293b 0%, #0f172a 100%);
                color: #f8fafc;
                font-family: 'Plus Jakarta Sans', sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                padding: 20px;
            }
            .checkout-card {
                background: rgba(30, 41, 59, 0.95);
                border: 1px solid rgba(16, 185, 129, 0.3);
                border-radius: 20px;
                padding: 40px;
                max-width: 460px;
                width: 100%;
                text-align: center;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 30px rgba(16, 185, 129, 0.1);
            }
            .cf-brand {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: rgba(16, 185, 129, 0.12);
                border: 1px solid rgba(16, 185, 129, 0.3);
                color: #10b981;
                font-size: 0.82rem;
                font-weight: 800;
                padding: 6px 14px;
                border-radius: 9999px;
                margin-bottom: 20px;
                letter-spacing: 0.5px;
            }
            .spinner {
                width: 56px;
                height: 56px;
                border: 4px solid rgba(16, 185, 129, 0.2);
                border-top-color: #10b981;
                border-radius: 50%;
                animation: spin 0.9s infinite linear;
                margin: 0 auto 24px auto;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
            .amount-badge {
                font-size: 2.2rem;
                font-weight: 800;
                color: #fff;
                margin: 12px 0 6px 0;
            }
            .details-box {
                background: rgba(15, 23, 42, 0.6);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 12px;
                padding: 16px;
                margin: 20px 0;
                text-align: left;
                font-size: 0.85rem;
            }
            .details-row {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            }
            .details-row:last-child { border-bottom: none; }
            .details-label { color: #94a3b8; }
            .details-val { font-weight: 700; color: #f8fafc; }
            .btn-pay {
                display: block;
                width: 100%;
                padding: 14px 20px;
                background: linear-gradient(135deg, #10b981, #059669);
                color: #ffffff;
                border: none;
                border-radius: 12px;
                font-size: 1rem;
                font-weight: 800;
                cursor: pointer;
                box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }
            .btn-pay:hover {
                transform: translateY(-2px);
                box-shadow: 0 15px 20px -3px rgba(16, 185, 129, 0.4);
            }
            .secure-footer {
                margin-top: 20px;
                font-size: 0.75rem;
                color: #64748b;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }
        </style>
    </head>
    <body>
        <div class="checkout-card">
            <div class="cf-brand">
                <span>⚡</span> SECURE CASHFREE PAYMENTS
            </div>
            
            <div class="spinner" id="spinner"></div>

            <h2 style="font-size: 1.25rem; font-weight: 800; color: #fff;">Redirecting to Payment Gateway</h2>
            <p style="color: #94a3b8; font-size: 0.85rem; margin-top: 6px;">UPI • PhonePe • GPay • Cards • NetBanking</p>

            <div class="amount-badge">₹<?=number_format($amount, 2)?></div>

            <div class="details-box">
                <div class="details-row">
                    <span class="details-label">Loan Application</span>
                    <span class="details-val"><?=htmlspecialchars($app['application_no'])?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Payment For</span>
                    <span class="details-val"><?=htmlspecialchars($productinfo)?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Customer</span>
                    <span class="details-val"><?=htmlspecialchars($firstname)?></span>
                </div>
            </div>

            <button type="button" id="payNowBtn" class="btn-pay" onclick="triggerCheckout('_modal')">
                Proceed to Pay ₹<?=number_format($amount, 2)?> →
            </button>

            <div style="margin-top: 12px;">
                <a href="javascript:void(0)" onclick="triggerCheckout('_self')" style="color: #60a5fa; font-size: 0.78rem; text-decoration: none;">
                    🖥️ Facing issue with popup? Click here for Full Screen Checkout →
                </a>
            </div>

            <div class="secure-footer">
                🔒 256-Bit SSL Encrypted • PCI-DSS Certified • RBI Authorized
            </div>
        </div>

        <script>
            let cashfreeInstance = null;
            try {
                cashfreeInstance = Cashfree({
                    mode: "<?=$cfCfg['env']?>"
                });
            } catch (e) {
                console.error("Cashfree initialization error:", e);
            }

            function triggerCheckout(mode) {
                if (!cashfreeInstance) {
                    alert("Cashfree SDK failed to initialize. Please check your internet connection.");
                    return;
                }
                const target = mode || "_modal";
                document.getElementById('payNowBtn').innerText = "Opening Cashfree...";
                document.getElementById('payNowBtn').disabled = true;

                cashfreeInstance.checkout({
                    paymentSessionId: "<?=$paymentSessionId?>",
                    redirectTarget: target
                }).then(function(result) {
                    if (result && result.error) {
                        console.warn("Cashfree checkout result:", result.error);
                        document.getElementById('payNowBtn').innerText = "Proceed to Pay ₹<?=number_format($amount, 2)?> →";
                        document.getElementById('payNowBtn').disabled = false;
                    }
                    if (result && (result.paymentDetails || result.paymentStatus === 'SUCCESS')) {
                        document.getElementById('payNowBtn').innerText = "✓ Payment Received! Verifying...";
                        window.location.href = "<?=url('/api/cashfree-emi-callback.php?order_id=' . $orderId)?>";
                    }
                }).catch(function(err) {
                    console.error("Cashfree checkout catch:", err);
                    if (target === "_modal") {
                        triggerCheckout('_self');
                    }
                });
            }

            // Auto-trigger checkout on page load
            window.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    triggerCheckout('_modal');
                }, 500);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==============================================================================
// 2. PAYU GATEWAY FALLBACK (IF SELECTED IN ADMIN SETTINGS)
// ==============================================================================
$udf1 = (string)$financeId;
$udf2 = (string)($emi ? $emi['id'] : 0);
$udf3 = (string)$app['customer_id'];
$udf4 = (string)($app['shop_id'] ?? 1);
$udf5 = '';

$emiKey  = get_setting('emi_payu_key') ?: PAYU_MERCHANT_KEY;
$emiSalt = get_setting('emi_payu_salt') ?: PAYU_SALT;
$emiEnv  = get_setting('emi_payu_env') ?: PAYU_ENV;
$payuBaseUrl = ($emiEnv === 'production') ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';

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
        body { background-color: #0f172a; color: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .loader-card { background: rgba(30, 41, 59, 0.9); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 40px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.4); }
        .spinner { width: 48px; height: 48px; border: 4px solid rgba(59, 130, 246, 0.2); border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s infinite linear; margin: 0 auto 20px auto; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loader-card">
        <div class="spinner"></div>
        <h2>Redirecting to PayU Secure Payment Gateway</h2>
        <p style="color: #94a3b8; margin-top: 8px;">Application: <strong><?=htmlspecialchars($app['application_no'])?></strong> | Amount: <strong>₹<?=number_format($amount, 2)?></strong></p>
        
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
