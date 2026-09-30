<?php
/**
 * Cashfree POS Terminal Activation Return Callback
 * Handles return after Cashfree PG checkout, verifies payment server-side,
 * activates the shop's POS terminal immediately upon successful payment,
 * records payment details, and redirects the shop owner with success confirmation.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cashfree.php';
require_once __DIR__ . '/../includes/pos_db_init.php';

$orderId = trim($_GET['order_id'] ?? ($_POST['order_id'] ?? ''));

if (empty($orderId)) {
    die("Invalid access: No order ID provided.");
}

$p = db();

// Lookup order in gateway_orders
$stmt = $p->prepare("SELECT * FROM gateway_orders WHERE order_id = ? LIMIT 1");
$stmt->execute([$orderId]);
$gOrder = $stmt->fetch();

$shopId = (int)($gOrder['shop_id'] ?? 0);
$amount = floatval($gOrder['amount'] ?? 1999);

// Verify Order with Cashfree API server-to-server
$cfRes = cashfree_get_order($orderId);

if (!$cfRes['success']) {
    $err = $cfRes['message'] ?? 'Could not verify payment with Cashfree.';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Payment Verification Failed - GO4FIN</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { background: #0f172a; color: #fff; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: #1e293b; border: 1px solid #ef4444; border-radius: 16px; padding: 36px; max-width: 480px; text-align: center; }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 10px; background: #3b82f6; color: #fff; text-decoration: none; font-weight: 700; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div style="font-size: 40px; margin-bottom: 10px;">❌</div>
            <h2 style="color: #ef4444;">Verification Failed</h2>
            <p style="color: #94a3b8; font-size: 14px; margin-top: 10px;"><?=htmlspecialchars($err)?></p>
            <a href="<?=url('/shop/pos.php')?>" class="btn">Return to Store</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$orderData   = $cfRes['data'];
$orderStatus = strtoupper(trim($orderData['order_status'] ?? ''));
$cfOrderId   = $orderData['cf_order_id'] ?? '';
$orderAmount = floatval($orderData['order_amount'] ?? $amount);

// Extract shop_id from tags if missing from gateway_orders
if ($shopId <= 0 && !empty($orderData['order_tags']['shop_id'])) {
    $shopId = (int)$orderData['order_tags']['shop_id'];
}

// Fetch Payment Details from Cashfree
$paymentsRes = cashfree_get_order_payments($orderId);
$cfPaymentId = '';
$paymentMode = 'Cashfree PG';

if ($paymentsRes['success'] && !empty($paymentsRes['data']) && is_array($paymentsRes['data'])) {
    $latestPayment = end($paymentsRes['data']);
    $cfPaymentId   = (string)($latestPayment['cf_payment_id'] ?? '');
    
    // Determine payment mode (UPI, Netbanking, Card, etc.)
    if (!empty($latestPayment['payment_method'])) {
        $pm = $latestPayment['payment_method'];
        if (is_array($pm)) {
            $keys = array_keys($pm);
            $paymentMode = 'Cashfree ' . strtoupper($keys[0] ?? 'ONLINE');
        } elseif (is_string($pm)) {
            $paymentMode = 'Cashfree ' . strtoupper($pm);
        }
    }
}

if ($orderStatus === 'PAID') {
    // 1. Activate shop's POS immediately
    if ($shopId > 0) {
        try {
            $p->prepare("UPDATE shops SET pos_active = 1, pos_activated_at = NOW(), pos_order_id = ?, pos_payment_ref = ? WHERE id = ?")
              ->execute([$orderId, $cfPaymentId, $shopId]);

            // Update gateway_orders status
            $p->prepare("UPDATE gateway_orders SET status = 'PAID', reference_no = ?, payment_mode = ?, cf_order_id = ?, updated_at = NOW() WHERE order_id = ?")
              ->execute([$cfPaymentId, $paymentMode, $cfOrderId, $orderId]);

            // Fetch shop name for logging
            $sName = $p->query("SELECT name FROM shops WHERE id = {$shopId}")->fetchColumn() ?: ('Shop #' . $shopId);

            // Audit log
            log_audit(
                'POS Addon Activated via Cashfree',
                'POS Terminal',
                "Shop #{$shopId} ({$sName}) activated POS Billing Addon via Cashfree payment ₹{$orderAmount} (Order: {$orderId}, Ref: {$cfPaymentId})"
            );

        } catch (Exception $e) {
            error_log("Error updating shop POS status: " . $e->getMessage());
        }
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>POS Terminal Activated Successfully! • GO4FIN</title>
        <link rel="icon" type="image/png" href="<?=url('/public/assets/images/logo.png')?>">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            .success-card {
                background: rgba(30, 41, 59, 0.95);
                border: 1px solid rgba(16, 185, 129, 0.4);
                border-radius: 24px;
                padding: 40px;
                max-width: 500px;
                width: 100%;
                text-align: center;
                box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.6), 0 0 40px rgba(16, 185, 129, 0.2);
                backdrop-filter: blur(12px);
                position: relative;
                overflow: hidden;
            }
            .success-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 5px;
                background: linear-gradient(90deg, #10b981, #3b82f6, #10b981);
            }
            .check-circle {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: rgba(16, 185, 129, 0.15);
                border: 2px solid #10b981;
                color: #10b981;
                font-size: 38px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px auto;
                box-shadow: 0 0 30px rgba(16, 185, 129, 0.3);
                animation: pop 0.4s ease-out;
            }
            @keyframes pop {
                0% { transform: scale(0.6); opacity: 0; }
                80% { transform: scale(1.1); }
                100% { transform: scale(1); opacity: 1; }
            }
            .details-box {
                background: rgba(15, 23, 42, 0.6);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 14px;
                padding: 16px 20px;
                margin: 24px 0;
                text-align: left;
                font-size: 0.85rem;
            }
            .details-row {
                display: flex;
                justify-content: space-between;
                padding: 7px 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            }
            .details-row:last-child { border-bottom: none; }
            .details-label { color: #94a3b8; }
            .details-val { font-weight: 700; color: #f8fafc; font-family: monospace; }
            .btn-pos {
                display: block;
                width: 100%;
                padding: 15px 24px;
                background: linear-gradient(135deg, #10b981, #059669);
                color: #ffffff;
                text-decoration: none;
                border-radius: 12px;
                font-size: 1rem;
                font-weight: 800;
                box-shadow: 0 10px 20px -3px rgba(16, 185, 129, 0.4);
                transition: all 0.2s ease;
            }
            .btn-pos:hover {
                transform: translateY(-2px);
                box-shadow: 0 15px 25px -3px rgba(16, 185, 129, 0.5);
            }
        </style>
    </head>
    <body>
        <div class="success-card">
            <div class="check-circle">✓</div>
            <span style="font-size: 0.8rem; font-weight: 800; color: #10b981; letter-spacing: 1px; text-transform: uppercase;">Payment Verified via Cashfree</span>
            <h2 style="font-size: 1.45rem; font-weight: 800; margin-top: 6px; color: #fff;">POS Terminal Unlocked!</h2>
            <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 6px; line-height: 1.5;">
                Congratulations! Your store now has lifetime access to the POS Billing & GST Tax Invoicing Terminal.
            </p>

            <div class="details-box">
                <div class="details-row">
                    <span class="details-label">Amount Paid</span>
                    <span class="details-val" style="color: #10b981; font-weight: 800; font-size: 1rem;">₹<?=number_format($orderAmount, 2)?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Order ID</span>
                    <span class="details-val"><?=htmlspecialchars($orderId)?></span>
                </div>
                <?php if (!empty($cfPaymentId)): ?>
                <div class="details-row">
                    <span class="details-label">Payment Ref</span>
                    <span class="details-val"><?=htmlspecialchars($cfPaymentId)?></span>
                </div>
                <?php endif; ?>
                <div class="details-row">
                    <span class="details-label">Payment Method</span>
                    <span class="details-val"><?=htmlspecialchars($paymentMode)?></span>
                </div>
                <div class="details-row">
                    <span class="details-label">Status</span>
                    <span class="details-val" style="color: #10b981;">ACTIVE & UNLOCKED</span>
                </div>
            </div>

            <a href="<?=url('/shop/pos.php?activated=1')?>" class="btn-pos">
                🚀 Open POS Billing Terminal Now →
            </a>
            
            <p style="color: #64748b; font-size: 0.78rem; margin-top: 14px;">
                Auto-redirecting in <span id="countdown">3</span> seconds...
            </p>
        </div>

        <script>
            let count = 3;
            const timer = setInterval(() => {
                count--;
                const el = document.getElementById('countdown');
                if (el) el.innerText = count;
                if (count <= 0) {
                    clearInterval(timer);
                    window.location.href = "<?=url('/shop/pos.php?activated=1')?>";
                }
            }, 1000);
        </script>
    </body>
    </html>
    <?php
    exit;

} else {
    // Payment Pending or Failed
    $statusText = ($orderStatus === 'ACTIVE') ? 'Pending Confirmation' : 'Payment Incomplete';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Status: <?=$statusText?> - GO4FIN</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { background: #0f172a; color: #fff; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: #1e293b; border: 1px solid #f59e0b; border-radius: 16px; padding: 36px; max-width: 480px; text-align: center; }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 10px; background: #3b82f6; color: #fff; text-decoration: none; font-weight: 700; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div style="font-size: 40px; margin-bottom: 10px;">⚠️</div>
            <h2 style="color: #f59e0b;"><?=$statusText?></h2>
            <p style="color: #94a3b8; font-size: 14px; margin-top: 10px; line-height: 1.6;">
                Your payment for POS terminal activation is currently reported as <strong><?=htmlspecialchars($orderStatus)?></strong> by Cashfree.
            </p>
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                <a href="<?=url('/api/pay-pos-activation.php')?>" class="btn" style="background: #f59e0b;">Try Payment Again</a>
                <a href="<?=url('/shop/pos.php')?>" class="btn" style="background: #334155;">Back to Store</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
