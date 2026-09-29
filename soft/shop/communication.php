<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
require_once __DIR__ . '/../includes/whatsapp.php';
role('shop_admin', 'superadmin', 'staff');

ensure_whatsapp_tables();

$p = db();
$u = u();
$shopId = (int)($u['shop_id'] ?? 0);

if ($shopId === 0 && $u['role'] === 'superadmin') {
    $shopId = (int)($p->query("SELECT id FROM shops ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

// Fetch Customers for Selector with their next EMI info
$custStmt = $p->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM emi_schedules e JOIN finance_applications f ON f.id = e.finance_id WHERE f.customer_id = c.id AND e.status != 'paid') as unpaid_emis_count,
           (SELECT e.amount FROM emi_schedules e JOIN finance_applications f ON f.id = e.finance_id WHERE f.customer_id = c.id AND e.status != 'paid' ORDER BY e.due_date ASC LIMIT 1) as next_emi_amount,
           (SELECT e.due_date FROM emi_schedules e JOIN finance_applications f ON f.id = e.finance_id WHERE f.customer_id = c.id AND e.status != 'paid' ORDER BY e.due_date ASC LIMIT 1) as next_due_date
    FROM customers c 
    WHERE c.shop_id = ? OR ? = 'superadmin' 
    ORDER BY c.name ASC
");
$custStmt->execute([$shopId, $u['role']]);
$customers = $custStmt->fetchAll();

$msg = '';
$err = '';
$sentCount = 0;
$failedCount = 0;

// Handle 1-Click 3-Day Automated Reminder Trigger
if (isset($_POST['trigger_3day_reminders'])) {
    $cronRes = waba_process_due_reminders(3);
    set_setting('last_whatsapp_reminder_cron_date', date('Y-m-d H:i:s'));
    $msg = "✓ 3-Day Prior WhatsApp EMI Reminder Scan completed! Target Due Date: <strong>" . date('d M Y', strtotime($cronRes['target_due_date'])) . "</strong>. Sent: <strong>{$cronRes['sent_count']}</strong> | Already Reminded (Skipped): <strong>{$cronRes['skipped_count']}</strong> | Failed: <strong>{$cronRes['failed_count']}</strong>.";
}

// Handle Communication Dispatch Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['trigger_3day_reminders'])) {
    try {
        $channel          = $_POST['channel'] ?? 'whatsapp';
        $targetType       = $_POST['target_type'] ?? 'single';
        $targetCustId     = (int)($_POST['customer_id'] ?? 0);
        $selectedCustIds  = isset($_POST['selected_customers']) && is_array($_POST['selected_customers']) ? array_map('intval', $_POST['selected_customers']) : [];
        $template         = $_POST['template'] ?? 'emi_reminder';
        $customSubject    = trim($_POST['custom_subject'] ?? '');
        $customMessage    = trim($_POST['custom_message'] ?? '');
        $customWabaName   = trim($_POST['custom_waba_template'] ?? '');
        $customWabaParams = trim($_POST['custom_waba_params'] ?? '');

        // Gather Target Customers based on selection
        $targetList = [];
        if ($targetType === 'all') {
            $targetList = $customers;
        } elseif ($targetType === 'all_dues') {
            foreach ($customers as $cItem) {
                if ((int)($cItem['unpaid_emis_count'] ?? 0) > 0) {
                    $targetList[] = $cItem;
                }
            }
        } elseif ($targetType === 'multiple') {
            if (empty($selectedCustIds)) {
                throw new Exception("Please check at least one customer from the list.");
            }
            foreach ($customers as $cItem) {
                if (in_array((int)$cItem['id'], $selectedCustIds, true)) {
                    $targetList[] = $cItem;
                }
            }
        } else {
            // Single Customer
            if ($targetCustId <= 0) {
                throw new Exception("Please select a valid customer.");
            }
            foreach ($customers as $cItem) {
                if ((int)$cItem['id'] === $targetCustId) {
                    $targetList[] = $cItem;
                    break;
                }
            }
        }

        if (empty($targetList)) {
            throw new Exception("No target customers found for dispatch.");
        }

        // =========================================================================
        // 1. WHATSAPP TEMPLATE DISPATCH (WABA API)
        // =========================================================================
        if ($channel === 'whatsapp') {
            $wabaFailures = [];

            foreach ($targetList as $c) {
                $custId = (int)$c['id'];
                $custMobile = trim($c['mobile'] ?? '');

                if (empty($custMobile)) {
                    $failedCount++;
                    $wabaFailures[] = "{$c['name']}: No mobile number";
                    continue;
                }

                if ($template === 'emi_reminder') {
                    // Send official emi_reminder template
                    $wRes = waba_send_emi_reminder($custId, null, 'admin_' . $u['id']);
                    if ($wRes['success']) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                        $wabaFailures[] = "{$c['name']}: " . ($wRes['message'] ?? 'Failed');
                    }
                } else {
                    // Custom / Future WhatsApp Template
                    $tmplName = !empty($customWabaName) ? $customWabaName : 'emi_reminder';
                    $rawParams = !empty($customWabaParams) ? explode(',', $customWabaParams) : [
                        $c['name'],
                        number_format((float)($c['next_emi_amount'] ?: 1000), 2),
                        $c['next_due_date'] ? date('d M Y', strtotime($c['next_due_date'])) : date('d M Y', strtotime('+3 days'))
                    ];
                    $cleanParams = array_map('trim', $rawParams);

                    $wRes = waba_send_template($custMobile, $tmplName, $cleanParams, 'en', [
                        'customer_id' => $custId,
                        'sent_by'     => 'admin_' . $u['id']
                    ]);

                    if ($wRes['success']) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                        $wabaFailures[] = "{$c['name']}: " . ($wRes['message'] ?? 'Failed');
                    }
                }
            }

            log_audit(
                'WhatsApp Template Dispatched',
                'Communication',
                "Dispatched '{$template}' via WhatsApp to {$sentCount} recipient(s). Failures: {$failedCount}.",
                $u['id']
            );

            if ($sentCount > 0) {
                $msg = "✓ WhatsApp Template Message dispatched successfully! Sent to <strong>{$sentCount}</strong> customer(s)." . ($failedCount > 0 ? " ({$failedCount} failed)" : "");
            } else {
                $err = "Could not send WhatsApp message. " . implode('; ', array_slice($wabaFailures, 0, 3));
            }

        // =========================================================================
        // 2. SMS CHANNEL (NOTICE)
        // =========================================================================
        } elseif ($channel === 'sms') {
            throw new Exception("📱 SMS Gateway: Please use 🟢 WhatsApp Template Message (Instant via Official WABA API) or 📧 Email Broadcast.");

        // =========================================================================
        // 3. EMAIL DISPATCH
        // =========================================================================
        } else {
            foreach ($targetList as $c) {
                $custName   = $c['name'];
                $custMobile = trim($c['mobile'] ?? '');
                $custEmail  = trim($c['email'] ?? '');
                
                $loginEmail    = !empty($custEmail) ? $custEmail : (!empty($custMobile) ? $custMobile . '@customer.local' : 'customer_' . $c['id'] . '@customer.local');
                $plainPassword = !empty($custMobile) ? $custMobile : '123456';
                $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

                // Ensure customer user account exists in `users`
                $uStmt = $p->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $uStmt->execute([$loginEmail]);
                $existingUser = $uStmt->fetch();

                if (!$existingUser) {
                    $insUser = $p->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, 'customer', 'active')");
                    $insUser->execute([$shopId, $custName, $loginEmail, $hashedPassword]);
                }

                $loginUrl = url('/login.php');

                if ($template === 'credentials') {
                    $subject = "🔑 Your GO4FIN Customer Portal Login Credentials";
                    $bodyHtml = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                        <div style='text-align: center; margin-bottom: 20px;'>
                            <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                            <p style='color: #64748b; font-size: 13px; margin-top: 4px;'>Certified Consumer Credit & Store Financing</p>
                        </div>
                        <div style='background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                            🔑 Customer Portal Access & Account Information
                        </div>
                        <p style='color: #334155; font-size: 14px;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                        <p style='color: #334155; font-size: 14px;'>Here are your official login credentials to access your GO4FIN Customer Financing Portal:</p>
                        <div style='background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 10px; margin: 20px 0;'>
                            <p style='margin: 6px 0; font-size: 13px;'><strong>Portal URL:</strong> <a href='" . $loginUrl . "' style='color: #2563eb; font-weight: bold;'>" . $loginUrl . "</a></p>
                            <p style='margin: 6px 0; font-size: 13px;'><strong>Username / Email:</strong> <span style='font-family: monospace; font-weight: bold;'>" . htmlspecialchars($loginEmail) . "</span></p>
                            <p style='margin: 6px 0; font-size: 13px;'><strong>Password:</strong> <span style='font-family: monospace; font-weight: bold; color: #059669;'>" . htmlspecialchars($plainPassword) . "</span></p>
                        </div>
                    </div>";

                } elseif ($template === 'emi_reminder') {
                    $emiAmtStr = money($c['next_emi_amount'] ?: 0);
                    $dueDateStr = $c['next_due_date'] ? date('d M Y', strtotime($c['next_due_date'])) : 'upcoming due date';

                    $subject = "⏰ Monthly EMI Payment Due Reminder — GO4FIN";
                    $bodyHtml = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                        <div style='text-align: center; margin-bottom: 20px;'>
                            <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                        </div>
                        <div style='background: #fffbeb; border: 1px solid #fde68a; color: #b45309; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                            ⏰ Important: Monthly EMI Repayment Due Reminder
                        </div>
                        <p style='color: #334155; font-size: 14px;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                        <p style='color: #334155; font-size: 14px;'>This is a reminder that your monthly installment is scheduled for payment on <strong>" . htmlspecialchars($dueDateStr) . "</strong>.</p>
                        <div style='background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 10px; margin: 20px 0;'>
                            <p style='margin: 6px 0; font-size: 14px;'><strong>EMI Amount:</strong> <span style='color: #059669; font-weight: bold;'>" . htmlspecialchars($emiAmtStr) . "</span></p>
                            <p style='margin: 6px 0; font-size: 13px;'><strong>Due Date:</strong> " . htmlspecialchars($dueDateStr) . "</p>
                        </div>
                    </div>";

                } else {
                    $subject = !empty($customSubject) ? $customSubject : "📢 Announcement from GO4 Finance Private Limited";
                    $bodyHtml = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                        <h2 style='color: #2563eb;'>GO4 FINANCE PRIVATE LIMITED</h2>
                        <p>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                        <div style='background: #f8fafc; padding: 16px; border-radius: 8px;'>" . nl2br(htmlspecialchars($customMessage)) . "</div>
                    </div>";
                }

                if (!empty($custEmail) && strpos($custEmail, '@customer.local') === false) {
                    send_email($custEmail, $subject, $bodyHtml);
                    $sentCount++;
                }
            }

            log_audit(
                'Customer Email Dispatched',
                'Communication',
                "Sent '{$template}' email to {$sentCount} customer(s).",
                $u['id']
            );

            $msg = "✓ Communication dispatched successfully! Sent notification email to {$sentCount} customer(s).";
        }

    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
}

// Fetch Combined Activity Logs (WhatsApp + Email)
$wabaLogsStmt = $p->query("
    SELECT wl.created_at, 'WhatsApp Template' as action, 
           CONCAT('Sent [', wl.template_name, '] to ', wl.mobile, ' (Status: ', wl.status, ')') as description,
           wl.sent_by as sender_name, wl.status
    FROM whatsapp_logs wl
    ORDER BY wl.id DESC LIMIT 30
");
$wabaLogs = $wabaLogsStmt->fetchAll();

$logsStmt = $p->prepare("
    SELECT a.created_at, a.action, a.description, u.name as sender_name, 'SENT' as status
    FROM audit_logs a 
    LEFT JOIN users u ON u.id = a.user_id 
    WHERE a.module = 'Communication' OR a.action LIKE '%Credentials%' 
    ORDER BY a.id DESC LIMIT 30
");
$logsStmt->execute();
$auditLogs = $logsStmt->fetchAll();

$allLogs = array_merge($wabaLogs, $auditLogs);
usort($allLogs, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
$allLogs = array_slice($allLogs, 0, 40);

start('Customer Communication & Messaging Hub');
?>

<!-- AUTOMATED 3-DAY REMINDER QUICK ACTION BAR -->
<div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, rgba(37,211,102,0.12), rgba(15,23,42,0.95)); border: 1px solid rgba(37,211,102,0.4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding: 18px 22px;">
    <div style="display: flex; align-items: center; gap: 14px;">
        <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(37,211,102,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
            🟢
        </div>
        <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                <span>WhatsApp AutoPay & Due Reminders (3 Days Prior)</span>
                <span class="badge badge-success" style="font-size: 0.72rem; padding: 2px 8px;">WABA LIVE</span>
            </h4>
            <p class="muted" style="font-size: 0.82rem; margin-top: 4px; color: #cbd5e1;">
                Template: <code>emi_reminder</code> | Automatically delivers 3 days before EMI due date to alert borrowers for sufficient balance.
            </p>
        </div>
    </div>
    <form method="POST" style="margin: 0;">
        <input type="hidden" name="trigger_3day_reminders" value="1">
        <button type="submit" class="btn" style="background: linear-gradient(135deg, #25D366, #128C7E); color: #fff; font-weight: 800; font-size: 0.85rem; padding: 10px 18px; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(37,211,102,0.3);">
            <span>⏰</span> Run 3-Day Reminder Scan Now
        </button>
    </form>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        <?=$msg?>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        ❌ <?=e($err)?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">

    <!-- DISPATCH MESSAGE FORM CARD -->
    <div class="card" style="border: 1px solid rgba(59,130,246,0.3);">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="send" style="color: var(--primary);"></i> Send Message to Customers
        </h3>
        <p class="muted" style="margin-bottom: 18px; font-size: 0.82rem;">Select recipient customers and dispatch WhatsApp template messages or email notifications instantly.</p>

        <form method="POST">

            <!-- CHANNEL SELECTOR -->
            <div class="field" style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 8px; display: block;">Select Communication Channel *</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <label id="lblChannelWhatsApp" style="background: rgba(37,211,102,0.18); border: 1px solid #25D366; padding: 10px 8px; border-radius: 8px; font-size: 0.82rem; font-weight: 800; cursor: pointer; color: #fff; text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <input type="radio" name="channel" value="whatsapp" checked onchange="handleChannelChange(this.value)"> 
                        <span>💬 WhatsApp</span>
                    </label>
                    <label id="lblChannelEmail" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); padding: 10px 8px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; color: var(--text-muted); text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <input type="radio" name="channel" value="email" onchange="handleChannelChange(this.value)"> 
                        <span>📧 Email</span>
                    </label>
                    <label id="lblChannelSMS" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); padding: 10px 8px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; color: var(--text-muted); text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <input type="radio" name="channel" value="sms" onchange="handleChannelChange(this.value)"> 
                        <span>📱 SMS</span>
                    </label>
                </div>
            </div>

            <!-- TARGET RECIPIENTS -->
            <div class="field" style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Target Recipient Audience *</label>
                <select name="target_type" id="targetTypeSelect" onchange="toggleRecipientType(this.value)" style="width: 100%; padding: 10px; background: #0f172a; color: #fff; border: 1px solid var(--border-color); border-radius: 8px;">
                    <option value="single">Select Specific Customer</option>
                    <option value="multiple">☑️ Select Multiple Customers (Checkboxes)</option>
                    <option value="all_dues">⏰ All Customers with Upcoming EMI Dues (<?=count(array_filter($customers, fn($c) => ($c['unpaid_emis_count'] ?? 0) > 0))?> Borrowers)</option>
                    <option value="all">📢 All Registered Store Customers (Bulk Broadcast - <?=count($customers)?>)</option>
                </select>
            </div>

            <!-- SINGLE CUSTOMER SELECTOR -->
            <div class="field" id="custSelectBox" style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Select Customer *</label>
                <select name="customer_id" style="width: 100%; padding: 10px; background: #0f172a; color: #fff; border: 1px solid var(--border-color); border-radius: 8px;">
                    <option value="">-- Choose Customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <?php 
                        $dueNote = ($c['unpaid_emis_count'] > 0) ? " [Due: ₹".number_format($c['next_emi_amount'], 2)." on ".date('d M', strtotime($c['next_due_date']))."]" : " [No Dues]";
                        ?>
                        <option value="<?=$c['id']?>">
                            <?=e($c['name'])?> (<?=e($c['mobile'])?>)<?=$dueNote?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- MULTIPLE CUSTOMER CHECKBOXES (HIDDEN BY DEFAULT) -->
            <div class="field" id="custMultiSelectBox" style="display: none; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.85rem; margin: 0;">Select Recipients:</label>
                    <button type="button" onclick="selectAllCustCheckboxes(true)" style="background: none; border: none; color: #60a5fa; font-size: 0.75rem; cursor: pointer; text-decoration: underline;">Select All</button>
                </div>
                <div style="max-height: 180px; overflow-y: auto; background: rgba(15,23,42,0.7); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px;">
                    <?php foreach ($customers as $c): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 0.82rem; cursor: pointer; color: #e2e8f0;">
                            <input type="checkbox" name="selected_customers[]" value="<?=$c['id']?>" class="cust-chk">
                            <span><strong><?=e($c['name'])?></strong> (<?=e($c['mobile'])?>)</span>
                            <?php if ($c['unpaid_emis_count'] > 0): ?>
                                <span class="badge badge-warning" style="font-size: 0.68rem; margin-left: auto;">₹<?=number_format($c['next_emi_amount'], 0)?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- TEMPLATE SELECTOR (FOR WHATSAPP) -->
            <div id="wabaTemplateBox" style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">WhatsApp Approved Template *</label>
                <select name="template" id="wabaTemplateSelect" onchange="toggleWabaTemplatePreview(this.value)" style="width: 100%; padding: 10px; background: #0f172a; color: #fff; border: 1px solid var(--border-color); border-radius: 8px;">
                    <option value="emi_reminder">🟢 emi_reminder (AutoPay & Due Date Reminder)</option>
                    <option value="custom_waba">⚙️ Other / Future Approved Template</option>
                </select>

                <!-- LIVE WHATSAPP BALLOON PREVIEW -->
                <div id="emiReminderPreview" style="margin-top: 12px; background: #0b141a; border: 1px solid rgba(37,211,102,0.3); border-radius: 12px; padding: 14px; position: relative;">
                    <div style="font-size: 0.72rem; color: #25D366; font-weight: 800; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                        <span>💬</span> WhatsApp Message Preview (emi_reminder)
                    </div>
                    <div style="background: #005c4b; color: #e9edef; font-size: 0.85rem; padding: 12px 14px; border-radius: 10px 10px 0 10px; line-height: 1.5; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                        Dear <strong>{{1: Customer Name}}</strong>, this is a reminder that your EMI of <strong>₹{{2: EMI Amount}}</strong> is scheduled for AutoPay on <strong>{{3: Due Date}}</strong>. Please ensure sufficient balance in your account for the payment.<br><br>
                        Thank you,<br>
                        <strong>GO4 FINANCE PVT LTD.</strong>
                    </div>
                    <p style="font-size: 0.74rem; color: #94a3b8; margin: 8px 0 0 0; line-height: 1.4;">
                        ℹ️ <em>Parameters <code>{{1}}</code>, <code>{{2}}</code>, and <code>{{3}}</code> are filled automatically from the customer's active loan and EMI schedule.</em>
                    </p>
                </div>

                <!-- CUSTOM WABA TEMPLATE FIELDS -->
                <div id="customWabaFields" style="display: none; margin-top: 12px; background: rgba(15,23,42,0.6); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="margin-bottom: 10px;">
                        <label style="font-size: 0.78rem; font-weight: 700; color: #94a3b8;">Template Name (Approved in Meta/WABA):</label>
                        <input type="text" name="custom_waba_template" placeholder="e.g. payment_receipt_alert" style="width: 100%; padding: 8px; font-size: 0.82rem; background: #0f172a; color: #fff; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>
                    <div>
                        <label style="font-size: 0.78rem; font-weight: 700; color: #94a3b8;">Body Parameters (comma-separated):</label>
                        <input type="text" name="custom_waba_params" placeholder="Param1, Param2, Param3" style="width: 100%; padding: 8px; font-size: 0.82rem; background: #0f172a; color: #fff; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>
                </div>
            </div>

            <!-- TEMPLATE SELECTOR (FOR EMAIL) -->
            <div id="emailTemplateBox" style="display: none; margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Email Message Template Preset *</label>
                <select name="template_email" id="emailTemplateSelect" onchange="toggleCustomMsgFields(this.value)" style="width: 100%; padding: 10px; background: #0f172a; color: #fff; border: 1px solid var(--border-color); border-radius: 8px;">
                    <option value="credentials">🔑 Resend Customer Login Credentials & Portal Link</option>
                    <option value="emi_reminder">⏰ EMI Monthly Payment Due & Overdue Reminder</option>
                    <option value="loan_approval">🛡️ Store Loan Approval & Verification Notice</option>
                    <option value="system_update">📢 Important System & Policy Update Notice</option>
                    <option value="festival_offer">🎁 Special Festive Offer & Down Payment Discount Alert</option>
                    <option value="custom">📝 Custom Subject & Rich Announcement</option>
                </select>

                <!-- CUSTOM EMAIL FIELDS -->
                <div id="customEmailFields" style="display: none; margin-top: 12px;">
                    <div class="field" style="margin-bottom: 10px;">
                        <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8;">Custom Email Subject *</label>
                        <input type="text" name="custom_subject" placeholder="e.g. Special Down Payment Offer for Valued Customer!" style="width: 100%; padding: 8px;">
                    </div>
                    <div class="field">
                        <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8;">Custom Message Body *</label>
                        <textarea name="custom_message" rows="3" placeholder="Enter custom message text..." style="width: 100%; padding: 8px;"></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn" style="width: 100%; padding: 13px; font-weight: 800; background: linear-gradient(135deg, #25D366, #128C7E); color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; cursor: pointer; box-shadow: 0 4px 14px rgba(37,211,102,0.4); margin-top: 10px;">
                🚀 Dispatch WhatsApp Template Message →
            </button>
        </form>
    </div>

    <!-- SENT HISTORY LOG TABLE -->
    <div class="card">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
            <span style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="history" style="color: #10b981;"></i> Communication Delivery Logs
            </span>
            <span style="font-size: 0.72rem; color: #94a3b8; font-weight: 500;">Latest 40 events</span>
        </h3>

        <div style="max-height: 520px; overflow-y: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                        <th style="padding: 10px;">Timestamp</th>
                        <th style="padding: 10px;">Channel / Event</th>
                        <th style="padding: 10px;">Details</th>
                        <th style="padding: 10px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allLogs)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 20px;" class="muted">No dispatched communication history found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($allLogs as $l): ?>
                            <?php 
                            $isWaba = (strpos($l['action'], 'WhatsApp') !== false);
                            $isSuccess = ($l['status'] === 'SENT');
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px; white-space: nowrap;">
                                    <strong><?=date('d M Y', strtotime($l['created_at']))?></strong><br>
                                    <span style="font-size:0.72rem; color:var(--text-muted);"><?=date('h:i A', strtotime($l['created_at']))?></span>
                                </td>
                                <td style="padding: 10px;">
                                    <?php if ($isWaba): ?>
                                        <span class="badge" style="background: rgba(37,211,102,0.18); color: #25D366; font-size:0.72rem;">🟢 WhatsApp</span>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="font-size:0.72rem;">📧 Email</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; font-size:0.78rem; color: #cbd5e1;"><?=e($l['description'])?></td>
                                <td style="padding: 10px;">
                                    <?php if ($isSuccess): ?>
                                        <span class="badge badge-success" style="font-size:0.7rem;">DELIVERED</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size:0.7rem;">FAILED</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function handleChannelChange(val) {
    const wabaBox = document.getElementById('wabaTemplateBox');
    const emailBox = document.getElementById('emailTemplateBox');
    const submitBtn = document.getElementById('submitBtn');

    const lblW = document.getElementById('lblChannelWhatsApp');
    const lblE = document.getElementById('lblChannelEmail');
    const lblS = document.getElementById('lblChannelSMS');

    // Reset styles
    lblW.style.background = 'rgba(255,255,255,0.05)';
    lblW.style.borderColor = 'var(--border-color)';
    lblW.style.color = 'var(--text-muted)';
    lblE.style.background = 'rgba(255,255,255,0.05)';
    lblE.style.borderColor = 'var(--border-color)';
    lblE.style.color = 'var(--text-muted)';
    lblS.style.background = 'rgba(255,255,255,0.05)';
    lblS.style.borderColor = 'var(--border-color)';
    lblS.style.color = 'var(--text-muted)';

    if (val === 'whatsapp') {
        wabaBox.style.display = 'block';
        emailBox.style.display = 'none';
        lblW.style.background = 'rgba(37,211,102,0.18)';
        lblW.style.borderColor = '#25D366';
        lblW.style.color = '#fff';
        submitBtn.style.background = 'linear-gradient(135deg, #25D366, #128C7E)';
        submitBtn.style.boxShadow = '0 4px 14px rgba(37,211,102,0.4)';
        submitBtn.innerText = '🚀 Dispatch WhatsApp Template Message →';
    } else if (val === 'email') {
        wabaBox.style.display = 'none';
        emailBox.style.display = 'block';
        lblE.style.background = 'rgba(59,130,246,0.18)';
        lblE.style.borderColor = '#3b82f6';
        lblE.style.color = '#fff';
        submitBtn.style.background = 'linear-gradient(135deg, var(--primary), #2563eb)';
        submitBtn.style.boxShadow = '0 4px 14px rgba(59,130,246,0.4)';
        submitBtn.innerText = '🚀 Dispatch Email Broadcast Now →';
    } else {
        wabaBox.style.display = 'none';
        emailBox.style.display = 'none';
        lblS.style.background = 'rgba(239,68,68,0.18)';
        lblS.style.borderColor = '#ef4444';
        lblS.style.color = '#fff';
        submitBtn.innerText = '📱 SMS Gateway (Notice)';
    }
}

function toggleRecipientType(val) {
    const singleBox = document.getElementById('custSelectBox');
    const multiBox = document.getElementById('custMultiSelectBox');
    if (val === 'single') {
        singleBox.style.display = 'block';
        multiBox.style.display = 'none';
    } else if (val === 'multiple') {
        singleBox.style.display = 'none';
        multiBox.style.display = 'block';
    } else {
        singleBox.style.display = 'none';
        multiBox.style.display = 'none';
    }
}

function selectAllCustCheckboxes(checked) {
    const boxes = document.querySelectorAll('.cust-chk');
    boxes.forEach(b => b.checked = checked);
}

function toggleWabaTemplatePreview(val) {
    const preview = document.getElementById('emiReminderPreview');
    const customFields = document.getElementById('customWabaFields');
    if (val === 'emi_reminder') {
        preview.style.display = 'block';
        customFields.style.display = 'none';
    } else {
        preview.style.display = 'none';
        customFields.style.display = 'block';
    }
}

function toggleCustomMsgFields(val) {
    document.getElementById('customEmailFields').style.display = val === 'custom' ? 'block' : 'none';
}
</script>

<?php render_end(); ?>
