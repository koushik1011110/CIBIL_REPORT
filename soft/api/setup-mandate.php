<?php
/**
 * Setup Cashfree Autopay / e-Mandate for Loan EMI Repayment
 * Initiates Cashfree Subscriptions flow, creates session, and opens checkout.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cashfree.php';

ensure_mandate_tables();

$user = u();
if (!$user) {
    header("Location: " . url('/login.php'));
    exit;
}

$financeId = isset($_GET['finance_id']) ? (int)$_GET['finance_id'] : (isset($_POST['finance_id']) ? (int)$_POST['finance_id'] : 0);
if ($financeId <= 0) {
    die("Invalid Loan Application ID.");
}

$p = db();

// Fetch Finance Application
$stmt = $p->prepare("
    SELECT f.*, c.name as customer_name, c.email as customer_email, c.mobile as customer_mobile
    FROM finance_applications f
    LEFT JOIN customers c ON c.id = f.customer_id
    WHERE f.id = ?
    LIMIT 1
");
$stmt->execute([$financeId]);
$app = $stmt->fetch();

if (!$app) {
    die("Loan Application not found.");
}

// Authorization check: Customer can only setup autopay for their own loan
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

    if ($loggedInCustId !== (int)$app['customer_id']) {
        die("Unauthorized access to this loan application.");
    }
}

// Check unpaid EMIs
$unpaidStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC");
$unpaidStmt->execute([$financeId]);
$unpaidEmis = $unpaidStmt->fetchAll();

if (empty($unpaidEmis)) {
    die("This loan has no outstanding EMIs to setup Autopay for.");
}

// Check if active mandate already exists
$existingMandate = get_active_loan_mandate($financeId);
if ($existingMandate && $existingMandate['status'] === 'ACTIVE') {
    header("Location: " . url('/customer/autopay.php?finance_id=' . $financeId . '&already_active=1'));
    exit;
}

// Autopay parameters calculation
$monthlyEmi = floatval($app['emi'] ?? ($unpaidEmis[0]['amount'] ?? 0));
if ($monthlyEmi <= 0) {
    $monthlyEmi = floatval($unpaidEmis[0]['amount'] ?? 100);
}

$remainingTenure = count($unpaidEmis);
// Max limit on mandate (NPCI standard allows setting a higher buffer for penal/interest safety, up to 2x or standard round)
$mandateMaxAmount = max(round($monthlyEmi * 1.5, 2), 5000.00);

// Customer details
$cleanName = preg_replace('/[^a-zA-Z0-9\s]/', '', trim($app['customer_name'] ?? 'Borrower'));
if (empty($cleanName)) $cleanName = 'Borrower';

$cleanPhone = preg_replace('/\D/', '', trim($app['customer_mobile'] ?? '9999999999'));
if (strlen($cleanPhone) > 10) $cleanPhone = substr($cleanPhone, -10);
if (strlen($cleanPhone) < 10) $cleanPhone = '9999999999';

$email = trim($app['customer_email'] ?: ($user['email'] ?? 'customer@go4fin.com'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = 'customer' . $app['customer_id'] . '@go4fin.com';
}

// Generate unique Mandate Subscription ID (max 45 chars)
$mandateId = 'MAN_' . $financeId . '_' . time() . rand(10, 99);
if (strlen($mandateId) > 40) {
    $mandateId = substr($mandateId, 0, 40);
}

// Host URL determination
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$customAppUrl = trim(get_setting('app_url', ''));
$baseUrlWithScheme = !empty($customAppUrl) ? rtrim($customAppUrl, '/') : ('https://' . $host);

$returnUrl = $baseUrlWithScheme . url('/api/cashfree-mandate-callback.php?mandate_id={subscription_id}&finance_id=' . $financeId);

// Build Subscriptions Payload for Cashfree PG API v2023-08-01
$subPayload = [
    'subscription_id' => $mandateId,
    'customer_details' => [
        'customer_id'    => 'cust_' . (int)$app['customer_id'],
        'customer_name'  => substr($cleanName, 0, 50),
        'customer_email' => $email,
        'customer_phone' => $cleanPhone
    ],
    'plan_details' => [
        'plan_name'             => substr('Loan EMI ' . ($app['application_no'] ?: '#' . $financeId), 0, 40),
        'plan_type'             => 'PERIODIC',
        'plan_currency'         => 'INR',
        'plan_amount'           => round($monthlyEmi, 2),
        'plan_recurring_amount' => round($monthlyEmi, 2),
        'plan_max_amount'       => round($mandateMaxAmount, 2),
        'plan_intervals'        => 1,
        'plan_interval_type'    => 'MONTH',
        'plan_max_cycles'       => max(1, $remainingTenure),
        'plan_note'             => 'Loan EMI Non-Revocable Mandate'
    ],
    'subscription_meta' => [
        'return_url'           => $returnUrl,
        'notification_channel' => ['SMS', 'EMAIL']
    ]
];

// Set first charge time if next EMI due date is in the future
$nextDue = $unpaidEmis[0]['due_date'] ?? null;
if ($nextDue && strtotime($nextDue) > time()) {
    $subPayload['subscription_first_charge_time'] = date('Y-m-d\TH:i:sP', strtotime($nextDue . ' 10:00:00'));
}

// Call Cashfree PG to create subscription
$createRes = cashfree_create_subscription($subPayload);

if (!$createRes['success'] || empty($createRes['subscription_session_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Autopay Setup Failed - GO4 Finance</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { background-color: #0f172a; color: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 32px; max-width: 500px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
            .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div style="font-size: 40px; margin-bottom: 12px;">⚠️</div>
            <h2 style="color: #f87171; margin: 0 0 10px 0;">Autopay Initiation Failed</h2>
            <p style="color: #cbd5e1; font-size: 14px; line-height: 1.6;"><?=htmlspecialchars($createRes['message'] ?? 'Unable to connect to Cashfree Subscriptions Gateway.')?></p>
            <a href="<?=url('/customer/emi-schedule.php?finance_id=' . $financeId)?>" class="btn" style="background: #334155; color: #cbd5e1;">← Return to EMI Schedule</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$subscriptionSessionId = $createRes['subscription_session_id'];
$cfSubscriptionId      = $createRes['cf_subscription_id'] ?? '';
$cfStatus              = $createRes['subscription_status'] ?? 'INITIALIZED';

// Next scheduled debit date is the next unpaid EMI due date
$nextDebitDate = $nextDue ? date('Y-m-d H:i:s', strtotime($nextDue)) : null;

// Insert or update mandate in database (non_revocable = 1, is_revocable = 0)
$stmtIns = $p->prepare("
    INSERT INTO loan_mandates (
        mandate_id, cf_subscription_id, subscription_session_id, finance_id, customer_id,
        plan_name, amount, max_amount, interval_type, intervals, max_cycles,
        auth_mode, is_revocable, non_revocable, status, next_debit_date, response_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'MONTH', 1, ?, 'UPI / e-NACH / Card', 0, 1, 'PENDING', ?, ?)
");
$stmtIns->execute([
    $mandateId,
    $cfSubscriptionId,
    $subscriptionSessionId,
    $financeId,
    $app['customer_id'],
    'Monthly Loan EMI - ' . ($app['application_no'] ?: '#' . $financeId),
    $monthlyEmi,
    $mandateMaxAmount,
    $remainingTenure,
    $nextDebitDate,
    json_encode($createRes['data'] ?? [])
]);

$cfCfg = cashfree_get_config();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Autopay (e-Mandate) · GO4 Finance</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        .mandate-card {
            background: rgba(30, 41, 59, 0.95);
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 20px;
            padding: 36px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 35px rgba(59, 130, 246, 0.15);
            position: relative;
            overflow: hidden;
        }
        .mandate-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, #3b82f6, #10b981, #6366f1);
        }
        .badge-brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
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
            animation: spin 0.9s infinite linear;
            margin: 0 auto 20px auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .details-box {
            background: rgba(15, 23, 42, 0.65);
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
            border-bottom: 1px dashed rgba(255, 255, 255, 0.06);
        }
        .details-row:last-child { border-bottom: none; }
        .btn-launch {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            margin-top: 14px;
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4);
            display: block;
            text-decoration: none;
        }
        .btn-cancel {
            display: inline-block;
            color: #94a3b8;
            font-size: 0.8rem;
            text-decoration: none;
            margin-top: 16px;
        }
        .btn-cancel:hover { color: #f87171; }
        .support-badges {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            margin-top: 16px;
            font-size: 0.72rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="mandate-card">
        <div class="badge-brand">
            <span>⚡</span> CASHFREE SECURE AUTOPAY / E-MANDATE
        </div>

        <div class="spinner" id="spinner"></div>

        <h2 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 6px; color: #fff;">
            Authorizing e-Mandate
        </h2>
        <p style="color: #94a3b8; font-size: 0.83rem; line-height: 1.5;">
            Opening Cashfree mandate authorization. Approve via <strong>UPI Autopay, NetBanking, or Debit Card</strong> for automatic monthly EMI debits.
        </p>

        <div class="details-box">
            <div class="details-row">
                <span style="color: #94a3b8;">Loan Application:</span>
                <span style="color: #fff; font-weight: 700;"><?=htmlspecialchars($app['application_no'] ?: '#' . $financeId)?></span>
            </div>
            <div class="details-row">
                <span style="color: #94a3b8;">Monthly Auto-Debit:</span>
                <span style="color: #10b981; font-weight: 800;">₹<?=number_format($monthlyEmi, 2)?> / month</span>
            </div>
            <div class="details-row">
                <span style="color: #94a3b8;">Frequency & Tenure:</span>
                <span style="color: #fff;">Monthly (<?=$remainingTenure?> Installments)</span>
            </div>
            <div class="details-row">
                <span style="color: #94a3b8;">Max Authorized Limit:</span>
                <span style="color: #60a5fa; font-weight: 700;">₹<?=number_format($mandateMaxAmount, 2)?></span>
            </div>
            <div class="details-row">
                <span style="color: #94a3b8;">Next EMI Due Date:</span>
                <span style="color: #fbbf24; font-weight: 600;"><?= $nextDue ? date('d M Y', strtotime($nextDue)) : 'Upcoming' ?></span>
            </div>
            <div class="details-row">
                <span style="color: #94a3b8;">Mandate Policy:</span>
                <span style="color: #38bdf8; font-weight: 700;">🔒 Non-Revocable (Loan EMI)</span>
            </div>
        </div>

        <button id="openCheckoutBtn" class="btn-launch" onclick="launchCashfreeSubscription()">
            🚀 Authorize Autopay in Cashfree
        </button>

        <a href="<?=url('/customer/emi-schedule.php?finance_id=' . $financeId)?>" class="btn-cancel">
            ✕ Cancel & Return to Schedule
        </a>

        <div class="support-badges">
            <span>🛡️ NPCI Compliant</span>
            <span>•</span>
            <span>🔒 Non-Revocable AutoPay</span>
            <span>•</span>
            <span>📱 UPI Autopay / e-NACH</span>
        </div>
    </div>

    <script>
        const sessionKey = "<?=htmlspecialchars($subscriptionSessionId)?>";
        const envMode    = "<?=htmlspecialchars($cfCfg['env'])?>";

        let cashfree = null;
        try {
            cashfree = Cashfree({ mode: envMode });
        } catch (e) {
            console.error("Cashfree SDK init error:", e);
        }

        function launchCashfreeSubscription() {
            if (!cashfree) {
                alert("Cashfree SDK failed to initialize. Please check your internet connection.");
                return;
            }

            const launchBtn = document.getElementById('openCheckoutBtn');
            if (launchBtn) {
                launchBtn.innerText = "Opening Cashfree Mandate...";
                launchBtn.disabled = true;
            }

            cashfree.subscriptionsCheckout({
                subsSessionId: sessionKey,
                redirectTarget: "_self"
            }).then(function(result) {
                if (result && result.error) {
                    alert("Autopay Authorization Error: " + result.error.message);
                    if (launchBtn) {
                        launchBtn.innerText = "🚀 Authorize Autopay in Cashfree";
                        launchBtn.disabled = false;
                    }
                }
                if (result && result.redirect) {
                    console.log("Redirecting to Cashfree Mandate Checkout...");
                }
            }).catch(function(err) {
                console.error("Checkout execution error:", err);
                if (launchBtn) {
                    launchBtn.innerText = "🚀 Authorize Autopay in Cashfree";
                    launchBtn.disabled = false;
                }
            });
        }

        // Auto launch on load after brief delay
        window.addEventListener('load', function() {
            setTimeout(function() {
                launchCashfreeSubscription();
            }, 600);
        });
    </script>
</body>
</html>
