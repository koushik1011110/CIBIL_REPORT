<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$u = u();
$shopId = (int)($u['shop_id'] ?? 0);

if ($shopId === 0 && $u['role'] === 'superadmin') {
    $shopId = (int)($p->query("SELECT id FROM shops ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

// Fetch Customers for Selector
$custStmt = $p->prepare("SELECT id, name, mobile, email FROM customers WHERE shop_id = ? OR ? = 'superadmin' ORDER BY name ASC");
$custStmt->execute([$shopId, $u['role']]);
$customers = $custStmt->fetchAll();

$msg = '';
$err = '';
$sentCount = 0;

// Handle Communication Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $channel      = $_POST['channel'] ?? 'email';
        $targetType   = $_POST['target_type'] ?? 'single';
        $targetCustId = (int)($_POST['customer_id'] ?? 0);
        $template     = $_POST['template'] ?? 'credentials';
        $customSubject = trim($_POST['custom_subject'] ?? '');
        $customMessage = trim($_POST['custom_message'] ?? '');

        // Gather Target Customers
        $targetList = [];
        if ($targetType === 'all') {
            $targetList = $customers;
        } else {
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

        if ($channel === 'sms') {
            // SMS Gateway Notice
            throw new Exception("📱 SMS Gateway API: Upcoming integration module. Please use 📧 Email Dispatch for instant delivery via configured SMTP server.");
        }

        foreach ($targetList as $c) {
            $custName  = $c['name'];
            $custMobile = trim($c['mobile'] ?? '');
            $custEmail = trim($c['email'] ?? '');
            
            $loginEmail = !empty($custEmail) ? $custEmail : (!empty($custMobile) ? $custMobile . '@customer.local' : 'customer_' . $c['id'] . '@customer.local');
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

            // Prepare Email Content based on Template
            $subject = '';
            $bodyHtml = '';
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

                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>Here are your official login credentials to access your GO4FIN Customer Financing Portal on your mobile or web browser.</p>

                    <div style='background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 10px; margin: 20px 0;'>
                        <h4 style='margin: 0 0 12px 0; color: #0f172a; font-size: 14px; text-transform: uppercase;'>🔑 Login Credentials</h4>
                        <p style='margin: 6px 0; font-size: 13px;'><strong>Portal URL:</strong> <a href='" . $loginUrl . "' style='color: #2563eb; font-weight: bold;'>" . $loginUrl . "</a></p>
                        <p style='margin: 6px 0; font-size: 13px;'><strong>Username / Email:</strong> <span style='font-family: monospace; font-weight: bold; color: #0f172a;'>" . htmlspecialchars($loginEmail) . "</span></p>
                        <p style='margin: 6px 0; font-size: 13px;'><strong>Password:</strong> <span style='font-family: monospace; font-weight: bold; color: #059669;'>" . htmlspecialchars($plainPassword) . "</span></p>
                    </div>

                    <h4 style='color: #0f172a; font-size: 14px; margin-top: 20px;'>📱 Key Portal Features Available:</h4>
                    <ul style='color: #475569; font-size: 13px; line-height: 1.8; padding-left: 20px;'>
                        <li><strong>Active Store Loans:</strong> View product loan details & outstanding balances.</li>
                        <li><strong>EMI Schedules:</strong> Check upcoming monthly installment due dates.</li>
                        <li><strong>Online Payments:</strong> Pay EMIs via UPI, QR code, or Debit Card.</li>
                        <li><strong>Documents & NOC:</strong> Download Digital Loan Agreement & Discharge NOC Certificates.</li>
                    </ul>

                    <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                        GO4 Finance Private Limited | Support Phone: +91 60005 47615 | Email: contact@go4fin.com
                    </div>
                </div>";

            } elseif ($template === 'emi_reminder') {
                $eStmt = $p->prepare("
                    SELECT e.*, f.application_no, p.name as product_name 
                    FROM emi_schedules e 
                    JOIN finance_applications f ON f.id = e.finance_id 
                    LEFT JOIN products p ON p.id = f.product_id 
                    WHERE f.customer_id = ? AND e.status != 'paid' 
                    ORDER BY e.due_date ASC LIMIT 1
                ");
                $eStmt->execute([$c['id']]);
                $nextEmi = $eStmt->fetch();

                $emiAmtStr = $nextEmi ? money($nextEmi['amount']) : 'monthly EMI';
                $dueDateStr = $nextEmi ? date('d M Y', strtotime($nextEmi['due_date'])) : 'upcoming due date';
                $appNo = $nextEmi ? $nextEmi['application_no'] : 'Active Loan';

                $subject = "⏰ EMI Payment Due Reminder — Application #" . $appNo;
                $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                        <p style='color: #64748b; font-size: 13px; margin-top: 4px;'>Certified Consumer Credit & Store Financing</p>
                    </div>

                    <div style='background: #fffbeb; border: 1px solid #fde68a; color: #b45309; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                        ⏰ Important: Monthly EMI Repayment Due Reminder
                    </div>

                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>This is a friendly reminder that your monthly installment for loan application <strong>#" . htmlspecialchars($appNo) . "</strong> is due on <strong>" . htmlspecialchars($dueDateStr) . "</strong>.</p>

                    <div style='background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 10px; margin: 20px 0;'>
                        <h4 style='margin: 0 0 12px 0; color: #0f172a; font-size: 14px; text-transform: uppercase;'>💵 Payment Summary</h4>
                        <p style='margin: 6px 0; font-size: 14px;'><strong>EMI Amount Due:</strong> <span style='color: #059669; font-size: 16px; font-weight: bold;'>" . htmlspecialchars($emiAmtStr) . "</span></p>
                        <p style='margin: 6px 0; font-size: 13px;'><strong>Due Date:</strong> " . htmlspecialchars($dueDateStr) . "</p>
                        <p style='margin: 12px 0 0 0;'><a href='" . $loginUrl . "' style='background: #2563eb; color: #ffffff; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;'>📲 Click Here to Pay Online via UPI / QR →</a></p>
                    </div>

                    <p style='color: #64748b; font-size: 12px; line-height: 1.5;'>Please ensure timely payment on or before the due date to avoid late penalty charges and preserve your CIBIL credit score.</p>

                    <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                        GO4 Finance Private Limited | Support Phone: +91 60005 47615 | Email: contact@go4fin.com
                    </div>
                </div>";

            } elseif ($template === 'loan_approval') {
                $subject = "🛡️ Store Loan Approval & Verification Notice — GO4FIN";
                $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                    </div>

                    <div style='background: #e6f4ea; border: 1px solid #ceead6; color: #137333; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                        🛡️ Your Store Loan Application Status: Approved!
                    </div>

                    <p style='color: #334155; font-size: 14px;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>We are pleased to notify you that your credit check and Aadhaar verification for store product financing has been approved by GO4 Finance Private Limited.</p>

                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>You can now complete down payment clearance at your nearest partner store counter or log in to your portal to manage your repayment schedule.</p>

                    <div style='text-align: center; margin: 20px 0;'>
                        <a href='" . $loginUrl . "' style='background: #2563eb; color: #ffffff; padding: 12px 22px; border-radius: 6px; text-decoration: none; font-weight: bold;'>📱 Access Customer Portal Now →</a>
                    </div>

                    <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                        GO4 Finance Private Limited | Support Phone: +91 60005 47615
                    </div>
                </div>";

            } elseif ($template === 'system_update') {
                $subject = "📢 Important Policy & Portal Update Notice — GO4FIN";
                $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                    </div>

                    <div style='background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                        📢 Important System & Customer Portal Announcement
                    </div>

                    <p style='color: #334155; font-size: 14px;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>We have upgraded our customer portal with instant online UPI repayment, digital loan agreement downloads, and automated NOC certificate generation.</p>

                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>Please log in to your account using your registered mobile number or email address to review your updated account statement.</p>

                    <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                        GO4 Finance Private Limited | Support Phone: +91 60005 47615
                    </div>
                </div>";

            } elseif ($template === 'festival_offer') {
                $subject = "🎁 Festive Offer: Low Down Payment & Zero Processing Fee on Mobiles & Appliances!";
                $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                    </div>

                    <div style='background: #faf5ff; border: 1px solid #e9d5ff; color: #6b21a8; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                        🎁 Special Festive Store Credit Offer!
                    </div>

                    <p style='color: #334155; font-size: 14px;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                    <p style='color: #334155; font-size: 14px; line-height: 1.6;'>Upgrade your Smartphone, Laptop, AC, or Smart TV today with <strong>Low Down Payment starting at ₹1,999</strong> and flexible 3 to 12 months EMI plans!</p>

                    <div style='background: #f8fafc; border: 1px solid #cbd5e1; padding: 16px; border-radius: 8px; margin: 16px 0; font-size: 13px; color: #334155;'>
                        ✔ 15-Minute Counter Approval<br>
                        ✔ 100% Digital Aadhaar & CIBIL Verification<br>
                        ✔ Zero Processing Fee for Existing Customers
                    </div>

                    <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                        GO4 Finance Private Limited | Partner Retail Stores across Assam
                    </div>
                </div>";

            } else {
                // Custom Announcement
                $subject = !empty($customSubject) ? $customSubject : "📢 Announcement from GO4 Finance Private Limited";
                $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                    </div>

                    <p style='color: #334155; font-size: 14px;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                    <div style='color: #334155; font-size: 14px; line-height: 1.6; background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #cbd5e1; margin: 16px 0;'>"
                        . nl2br(htmlspecialchars($customMessage)) . 
                    "</div>

                    <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                        GO4 Finance Private Limited | Support Phone: +91 60005 47615
                    </div>
                </div>";
            }

            // Dispatch Email if email is available
            if (!empty($custEmail) && strpos($custEmail, '@customer.local') === false) {
                send_email($custEmail, $subject, $bodyHtml);
                $sentCount++;
                
                log_audit(
                    'Customer Email Dispatched',
                    'Communication',
                    "Sent '{$template}' email to {$custName} ({$custEmail}). Subject: {$subject}",
                    $u['id']
                );
            }
        }

        $msg = "✓ Communication dispatched successfully! Sent notification email to {$sentCount} customer(s).";

    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
}

// Fetch Communication Logs
$logsStmt = $p->prepare("
    SELECT a.*, u.name as sender_name 
    FROM audit_logs a 
    LEFT JOIN users u ON u.id = a.user_id 
    WHERE a.module = 'Communication' OR a.action LIKE '%Credentials%' 
    ORDER BY a.id DESC LIMIT 50
");
$logsStmt->execute();
$logs = $logsStmt->fetchAll();

start('Customer Communication & Messaging Hub');
?>

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
            <i data-lucide="send" style="color: var(--primary);"></i> Dispatch Communication to Customer
        </h3>
        <p class="muted" style="margin-bottom: 18px; font-size: 0.82rem;">Resend login credentials, EMI payment reminders, loan status updates, festival offers, or custom announcements.</p>

        <form method="POST">

            <!-- CHANNEL SELECTOR -->
            <div class="field" style="margin-bottom: 14px;">
                <label>Communication Channel *</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <label style="background: rgba(59,130,246,0.15); border: 1px solid var(--primary); padding: 10px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; cursor: pointer; color: #fff;">
                        <input type="radio" name="channel" value="email" checked> 📧 Email Broadcast
                    </label>
                    <label style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); padding: 10px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; cursor: pointer; color: var(--text-muted);" title="Upcoming SMS Gateway module">
                        <input type="radio" name="channel" value="sms"> 📱 SMS Alert (Upcoming)
                    </label>
                </div>
            </div>

            <!-- TARGET RECIPIENTS -->
            <div class="field" style="margin-bottom: 14px;">
                <label>Target Recipient Audience *</label>
                <select name="target_type" id="targetTypeSelect" onchange="toggleCustDropdown(this.value)" style="width: 100%; padding: 10px;">
                    <option value="single">Select Specific Customer</option>
                    <option value="all">📢 All Registered Store Customers (Bulk Broadcast)</option>
                </select>
            </div>

            <!-- SINGLE CUSTOMER SELECTOR -->
            <div class="field" id="custSelectBox" style="margin-bottom: 14px;">
                <label>Select Customer *</label>
                <select name="customer_id" style="width: 100%; padding: 10px;">
                    <option value="">-- Choose Customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?=$c['id']?>"><?=e($c['name'])?> (<?=e($c['mobile'])?> <?=e($c['email'] ? '· '.$c['email'] : '')?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- TEMPLATE PRESET -->
            <div class="field" style="margin-bottom: 14px;">
                <label>Message Template Preset *</label>
                <select name="template" id="templateSelect" onchange="toggleCustomMsgFields(this.value)" style="width: 100%; padding: 10px;">
                    <option value="credentials">🔑 Resend Customer Login Credentials & Portal Link</option>
                    <option value="emi_reminder">⏰ EMI Monthly Payment Due & Overdue Reminder</option>
                    <option value="loan_approval">🛡️ Store Loan Approval & Verification Notice</option>
                    <option value="system_update">📢 Important System & Policy Update Notice</option>
                    <option value="festival_offer">🎁 Special Festive Offer & Down Payment Discount Alert</option>
                    <option value="custom">📝 Custom Subject & Rich Announcement</option>
                </select>
            </div>

            <!-- CUSTOM MESSAGE FIELDS (HIDDEN BY DEFAULT) -->
            <div id="customFields" style="display: none;">
                <div class="field" style="margin-bottom: 14px;">
                    <label>Custom Email Subject *</label>
                    <input type="text" name="custom_subject" placeholder="e.g. Special Down Payment Offer for Valued Customer!">
                </div>
                <div class="field" style="margin-bottom: 14px;">
                    <label>Custom Message Body *</label>
                    <textarea name="custom_message" rows="4" placeholder="Enter custom message text..."></textarea>
                </div>
            </div>

            <button type="submit" class="btn" style="width: 100%; padding: 13px; font-weight: 800; background: linear-gradient(135deg, var(--primary), #2563eb); margin-top: 10px;">
                🚀 Dispatch Communication Now →
            </button>
        </form>
    </div>

    <!-- SENT HISTORY LOG TABLE -->
    <div class="card">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="history" style="color: #10b981;"></i> Sent Communication Activity Logs
        </h3>

        <div style="max-height: 380px; overflow-y: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                        <th style="padding: 10px;">Timestamp</th>
                        <th style="padding: 10px;">Dispatched Event</th>
                        <th style="padding: 10px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="3" style="text-align: center; padding: 20px;" class="muted">No dispatched communication history found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px; white-space: nowrap;">
                                    <strong><?=date('d M Y', strtotime($l['created_at']))?></strong><br>
                                    <span style="font-size:0.72rem; color:var(--text-muted);"><?=date('h:i A', strtotime($l['created_at']))?></span>
                                </td>
                                <td style="padding: 10px;">
                                    <span class="badge badge-info" style="font-size:0.72rem;"><?=e($l['action'])?></span>
                                </td>
                                <td style="padding: 10px; font-size:0.78rem; color:var(--text-muted);"><?=e($l['description'])?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function toggleCustDropdown(val) {
    document.getElementById('custSelectBox').style.display = val === 'all' ? 'none' : 'block';
}

function toggleCustomMsgFields(val) {
    document.getElementById('customFields').style.display = val === 'custom' ? 'block' : 'none';
}
</script>

<?php render_end(); ?>
