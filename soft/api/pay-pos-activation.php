<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cashfree.php';
auth();

$user = u();
if (!in_array($user['role'] ?? '', ['shop_admin', 'staff', 'superadmin'])) {
    http_response_code(403);
    die("Access denied: You must be logged in as a shop administrator.");
}

$p = db();
$shopId = (int)($user['shop_id'] ?? 0);
if ($user['role'] === 'superadmin') {
    $shopId = isset($_REQUEST['shop_id']) ? (int)$_REQUEST['shop_id'] : ($shopId ?: 1);
}

if ($shopId <= 0) {
    die("Invalid Shop Account. No shop associated with current user.");
}

// Fetch shop details
$sStmt = $p->prepare("SELECT * FROM shops WHERE id = ?");
$sStmt->execute([$shopId]);
$shop = $sStmt->fetch();

if (!$shop) {
    die("Shop record not found.");
}

// If already activated, redirect to POS terminal
if ((int)($shop['pos_active'] ?? 0) === 1) {
    header("Location: " . url('/shop/pos.php?msg=already_active'));
    exit;
}

$price = floatval(get_setting('pos_activation_price', '1999'));
if ($price <= 0) {
    $price = 1999.00;
}
$amountFormatted = sprintf('%.2f', $price);

$shopName = trim($shop['name'] ?: 'Store #' . $shopId);
$email    = trim($shop['email'] ?: ($user['email'] ?? 'shop' . $shopId . '@go4fin.com'));
$phone    = trim($shop['phone'] ?: '9999999999');

// Clean Phone number (Cashfree requires 10 digit number)
$cleanPhone = preg_replace('/\D/', '', $phone);
if (strlen($cleanPhone) > 10) {
    $cleanPhone = substr($cleanPhone, -10);
}
if (strlen($cleanPhone) < 10) {
    $cleanPhone = '9999999999';
}

// Clean Shop Name
$cleanName = preg_replace('/[^a-zA-Z0-9\s]/', '', $shopName);
if (empty($cleanName)) {
    $cleanName = 'Shop ' . $shopId;
}

// Check Cashfree Configuration
if (!cashfree_is_configured()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Gateway Setup Required - GO4FIN</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { background-color: #0f172a; color: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 36px; max-width: 480px; text-align: center; box-shadow: 0 20px 30px rgba(0,0,0,0.5); }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 10px; text-decoration: none; font-weight: 700; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div style="font-size: 44px; margin-bottom: 12px;">⚙️</div>
            <h2 style="color: #f87171; margin: 0 0 10px 0; font-size: 1.3rem;">Cashfree Gateway Configuration Required</h2>
            <p style="color: #94a3b8; font-size: 14px; line-height: 1.6;">The Cashfree App ID and Secret Key have not been configured yet in the Admin System Settings.</p>
            <p style="color: #64748b; font-size: 13px;">Please contact the administrator or support to complete gateway setup.</p>
            <a href="<?=url('/shop/pos.php')?>" class="btn" style="background: #334155; color: #cbd5e1;">← Back to Store</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$cfCfg = cashfree_get_config();

// Unique Order ID (max 45 chars)
$orderId = 'CF_POS_' . $shopId . '_' . time() . rand(10, 99);
if (strlen($orderId) > 44) {
    $orderId = substr($orderId, 0, 44);
}

// Cashfree PG return_url
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$customAppUrl = trim(get_setting('app_url', ''));
if (!empty($customAppUrl)) {
    $baseUrlWithScheme = rtrim($customAppUrl, '/');
} else {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'https://';
    $baseUrlWithScheme = $scheme . $host;
}

$returnUrl = $baseUrlWithScheme . url('/api/cashfree-pos-callback.php?order_id={order_id}');
$orderMeta = [
    'return_url' => $returnUrl
];

$isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1']) || preg_match('/^192\.168\./', $host);
if (!empty($customAppUrl) || !$isLocalhost) {
    $orderMeta['notify_url'] = $baseUrlWithScheme . url('/api/cashfree-pos-webhook.php');
}

$cfOrderPayload = [
    'order_id'         => $orderId,
    'order_amount'     => round($price, 2),
    'order_currency'   => 'INR',
    'customer_details' => [
        'customer_id'    => 'shop_' . $shopId,
        'customer_name'  => substr($cleanName, 0, 50),
        'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'shop_' . $shopId . '@go4fin.com',
        'customer_phone' => $cleanPhone
    ],
    'order_meta' => $orderMeta,
    'order_note' => 'POS Terminal Activation for ' . substr($shopName, 0, 50),
    'order_tags' => [
        'type'    => 'pos_activation',
        'shop_id' => (string)$shopId,
        'user_id' => (string)$user['id']
    ]
];

$createRes = cashfree_create_order($cfOrderPayload);

if (!$createRes['success'] || empty($createRes['payment_session_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Initiation Failed - GO4FIN</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { background-color: #0f172a; color: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 36px; max-width: 480px; text-align: center; box-shadow: 0 20px 30px rgba(0,0,0,0.5); }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 10px; text-decoration: none; font-weight: 700; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div style="font-size: 44px; margin-bottom: 12px;">⚠️</div>
            <h2 style="color: #f87171; margin: 0 0 10px 0; font-size: 1.3rem;">Payment Initiation Failed</h2>
            <p style="color: #cbd5e1; font-size: 14px; line-height: 1.6;"><?=htmlspecialchars($createRes['message'] ?? 'Unable to connect to Cashfree Payment Gateway.')?></p>
            <a href="<?=url('/shop/pos.php')?>" class="btn" style="background: #334155; color: #cbd5e1;">← Return to Store</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$paymentSessionId = $createRes['payment_session_id'];
$cfOrderId        = $createRes['cf_order_id'] ?? '';

// Record order in gateway_orders
try {
    $stmtIns = $p->prepare("
        INSERT INTO gateway_orders (order_id, cf_order_id, shop_id, amount, gateway, order_type, payment_session_id, status)
        VALUES (?, ?, ?, ?, 'cashfree', 'POS_ACTIVATION', ?, 'PENDING')
    ");
    $stmtIns->execute([
        $orderId,
        $cfOrderId,
        $shopId,
        $price,
        $paymentSessionId
    ]);
} catch (Exception $e) {
    error_log("Failed to insert gateway_orders for POS: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activating POS Terminal • GO4FIN Cashfree Checkout</title>
    <link rel="icon" type="image/png" href="<?=url('/public/assets/images/logo.png')?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: radial-gradient(circle at 50% 20%, #1e293b 0%, #0b1120 100%);
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
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            padding: 36px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.6), 0 0 35px rgba(59, 130, 246, 0.15);
            backdrop-filter: blur(12px);
            position: relative;
            overflow: hidden;
        }
        .checkout-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #10b981, #f59e0b);
        }
        .cf-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
            font-size: 0.8rem;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 9999px;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }
        .spinner {
            width: 52px;
            height: 52px;
            border: 4px solid rgba(59, 130, 246, 0.2);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.85s infinite linear;
            margin: 0 auto 20px auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .amount-badge {
            font-size: 2.4rem;
            font-weight: 800;
            color: #ffffff;
            margin: 12px 0 6px 0;
            letter-spacing: -0.5px;
        }
        .amount-badge span {
            color: #10b981;
        }
        .details-box {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 16px 20px;
            margin: 22px 0;
            text-align: left;
            font-size: 0.85rem;
        }
        .details-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .details-row:last-child { border-bottom: none; }
        .details-label { color: #94a3b8; }
        .details-val { font-weight: 700; color: #f8fafc; }
        .btn-pay {
            display: block;
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 1.02rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 10px 20px -3px rgba(37, 99, 235, 0.4);
            transition: all 0.2s ease;
        }
        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -3px rgba(37, 99, 235, 0.5);
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        .btn-pay:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .secure-footer {
            margin-top: 22px;
            font-size: 0.74rem;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .features-pill-row {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }
        .feature-pill {
            font-size: 0.72rem;
            background: rgba(255, 255, 255, 0.05);
            padding: 3px 10px;
            border-radius: 20px;
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body>
    <div class="checkout-card">
        <div class="cf-badge">
            <span>⚡</span> SECURE CASHFREE CHECKOUT
        </div>
        
        <div class="spinner" id="spinner"></div>

        <h2 style="font-size: 1.3rem; font-weight: 800; color: #fff;">Activate POS Terminal</h2>
        <p style="color: #94a3b8; font-size: 0.85rem; margin-top: 4px;">Lifetime License for GST Billing & Store POS</p>

        <div class="amount-badge">
            <span>₹</span><?=number_format($price, 2)?>
        </div>

        <div class="features-pill-row">
            <span class="feature-pill">✓ Lifetime Validity</span>
            <span class="feature-pill">✓ Thermal Print</span>
            <span class="feature-pill">✓ GST Invoicing</span>
            <span class="feature-pill">✓ Barcode Scanning</span>
        </div>

        <div class="details-box">
            <div class="details-row">
                <span class="details-label">Store Name</span>
                <span class="details-val"><?=htmlspecialchars($shopName)?></span>
            </div>
            <div class="details-row">
                <span class="details-label">Module</span>
                <span class="details-val" style="color: #60a5fa;">POS Billing Addon</span>
            </div>
            <div class="details-row">
                <span class="details-label">Total Payable</span>
                <span class="details-val" style="color: #10b981; font-size: 1rem;">₹<?=number_format($price, 2)?></span>
            </div>
        </div>

        <button type="button" id="payNowBtn" class="btn-pay" onclick="triggerCheckout('_modal')">
            Pay ₹<?=number_format($price, 2)?> & Activate Instantly →
        </button>

        <div style="margin-top: 14px;">
            <a href="javascript:void(0)" onclick="triggerCheckout('_self')" style="color: #60a5fa; font-size: 0.78rem; text-decoration: none;">
                🖥️ Facing issues with popup? Click here for Full Page Checkout →
            </a>
        </div>

        <div style="margin-top: 10px;">
            <a href="<?=url('/shop/dashboard.php')?>" style="color: #94a3b8; font-size: 0.78rem; text-decoration: underline;">
                Cancel and return to Dashboard
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
                alert("Cashfree SDK failed to initialize. Please check your internet connection and reload.");
                return;
            }
            const target = mode || "_modal";
            const btn = document.getElementById('payNowBtn');
            btn.innerText = "Opening Cashfree Gateway...";
            btn.disabled = true;

            cashfreeInstance.checkout({
                paymentSessionId: "<?=$paymentSessionId?>",
                redirectTarget: target
            }).then(function(result) {
                if (result.error) {
                    console.error("Cashfree Checkout Error:", result.error);
                    alert("Payment window closed or encounter an error: " + (result.error.message || 'Please retry.'));
                    btn.disabled = false;
                    btn.innerText = "Pay ₹<?=number_format($price, 2)?> & Activate Instantly →";
                }
                if (result.redirect) {
                    console.log("Payment redirecting...");
                }
            }).catch(function(err) {
                console.error("Cashfree Checkout exception:", err);
                btn.disabled = false;
                btn.innerText = "Pay ₹<?=number_format($price, 2)?> & Activate Instantly →";
            });
        }

        // Auto-launch modal after 800ms
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                triggerCheckout('_modal');
            }, 800);
        });
    </script>
</body>
</html>
