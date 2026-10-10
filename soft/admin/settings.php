<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();
$msg = '';
$err = '';

// Handle Test SMTP Email POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_smtp_email') {
    $testEmail = trim($_POST['test_email_address'] ?? '');
    if (!empty($testEmail)) {
        $sent = send_email(
            $testEmail,
            "SMTP Test Email — GO4 Finance Private Limited",
            "<div style='font-family: Arial, sans-serif; padding: 20px; color: #1e293b;'><h2 style='color: #2563eb;'>✓ SMTP Server Connection Successful!</h2><p>This is a test transactional email sent from your <strong>GO4 Finance Private Limited</strong> portal to confirm your SMTP Mail Server settings.</p><p style='font-size: 12px; color: #64748b;'>Sent at: " . date('d M Y, h:i A') . "</p></div>"
        );
        if ($sent) {
            header('Location: settings.php?msg=email_sent');
        } else {
            header('Location: settings.php?msg=email_failed');
        }
        exit;
    }
}

// Handle Settings Save POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'test_smtp_email')) {
    $settings = [
        'theme_mode'             => $_POST['theme_mode'] ?? 'light',
        'emi_active_gateway'     => $_POST['emi_active_gateway'] ?? 'cashfree',
        'emi_cashfree_app_id'    => trim($_POST['emi_cashfree_app_id'] ?? ''),
        'emi_cashfree_secret_key'=> trim($_POST['emi_cashfree_secret_key'] ?? ''),
        'emi_cashfree_env'       => $_POST['emi_cashfree_env'] ?? 'sandbox',
        'emi_payu_key'           => trim($_POST['emi_payu_key'] ?? ''),
        'emi_payu_salt'          => trim($_POST['emi_payu_salt'] ?? ''),
        'emi_payu_env'           => $_POST['emi_payu_env'] ?? 'production',
        'pos_addon_activated'    => $_POST['pos_addon_activated'] ?? '0',
        'pos_addon_api_key'      => trim($_POST['pos_addon_api_key'] ?? ''),
        'pos_activation_price'   => trim($_POST['pos_activation_price'] ?? '1999'),
        'smtp_host'              => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'              => trim($_POST['smtp_port'] ?? '587'),
        'smtp_encryption'        => $_POST['smtp_encryption'] ?? 'tls',
        'smtp_username'          => trim($_POST['smtp_username'] ?? ''),
        'smtp_password'          => trim($_POST['smtp_password'] ?? ''),
        'smtp_from_email'        => trim($_POST['smtp_from_email'] ?? ''),
        'smtp_from_name'         => trim($_POST['smtp_from_name'] ?? ''),
        'bureau_test_mode'       => $_POST['bureau_test_mode'] ?? '0',
        'allow_new_to_credit_finance' => $_POST['allow_new_to_credit_finance'] ?? '1',
        'finpay_credit_api_key'  => trim($_POST['finpay_credit_api_key'] ?? '')
    ];

    foreach ($settings as $key => $val) {
        $stmt = $p->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $val]);
    }

    log_audit('Settings Update', 'Settings', 'Updated system settings, Cashfree EMI gateway credentials, and SMTP email setup.', u()['id']);

    header('Location: settings.php?msg=saved');
    exit;
}

$themeMode          = get_setting('theme_mode', 'light');
$emiActiveGateway   = get_setting('emi_active_gateway', 'cashfree');
$emiCashfreeAppId   = get_setting('emi_cashfree_app_id', '');
$emiCashfreeSecret  = get_setting('emi_cashfree_secret_key', '');
$emiCashfreeEnv     = get_setting('emi_cashfree_env', 'sandbox');
$emiPayuKey         = get_setting('emi_payu_key', '');
$emiPayuSalt        = get_setting('emi_payu_salt', '');
$emiPayuEnv         = get_setting('emi_payu_env', 'production');
$posActivated       = get_setting('pos_addon_activated', '0');
$posApiKey          = get_setting('pos_addon_api_key', '');
$posPrice           = get_setting('pos_activation_price', '1999');


$smtpHost     = get_setting('smtp_host', '');
$smtpPort     = get_setting('smtp_port', '587');
$smtpEnc      = get_setting('smtp_encryption', 'tls');
$smtpUser     = get_setting('smtp_username', '');
$smtpPass     = get_setting('smtp_password', '');
$smtpFromEmail = get_setting('smtp_from_email', 'contact@go4fin.com');
$smtpFromName  = get_setting('smtp_from_name', 'GO4 Finance Private Limited');

$bureauTestMode    = get_setting('bureau_test_mode', '0');
$allowNtcFinance   = get_setting('allow_new_to_credit_finance', '1');
$finpayApiKey      = get_setting('finpay_credit_api_key', '8d8fd1-efeaa9-928494-24a4fd-0c7dd1');

start('System Settings & Gateway Config');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'saved'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Settings Saved!</strong> System settings, POS license, EMI gateway & SMTP email configuration updated successfully.
        </div>
    <?php elseif ($_GET['msg'] === 'email_sent'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>📧 Test Email Sent Successfully!</strong> Check your inbox to verify delivery.
        </div>
    <?php elseif ($_GET['msg'] === 'email_failed'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            ❌ <strong>Test Email Failed!</strong> Please verify your SMTP Host, Port, Username, and Password credentials.
        </div>
    <?php endif; ?>
<?php endif; ?>

<form method="post">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; margin-bottom: 24px;">

        <!-- SMTP EMAIL SETUP CARD -->
        <div class="card" style="border: 1px solid rgba(59, 130, 246, 0.4);">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="mail" style="color: var(--primary);"></i> SMTP Mail Server Setup
            </h3>

            <div class="field" style="margin-bottom: 14px;">
                <label>SMTP Host Server *</label>
                <input name="smtp_host" value="<?=e($smtpHost)?>" placeholder="e.g. smtp.gmail.com or mail.go4fin.com" style="width: 100%; padding: 10px;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                <div class="field">
                    <label>Port</label>
                    <input name="smtp_port" value="<?=e($smtpPort)?>" placeholder="587 / 465" style="width: 100%; padding: 10px;">
                </div>
                <div class="field">
                    <label>Encryption</label>
                    <select name="smtp_encryption" style="width: 100%; padding: 10px;">
                        <option value="tls" <?=$smtpEnc === 'tls' ? 'selected' : ''?>>TLS (Port 587)</option>
                        <option value="ssl" <?=$smtpEnc === 'ssl' ? 'selected' : ''?>>SSL (Port 465)</option>
                        <option value="none" <?=$smtpEnc === 'none' ? 'selected' : ''?>>None (Plain)</option>
                    </select>
                </div>
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>SMTP Username / Login Email</label>
                <input name="smtp_username" value="<?=e($smtpUser)?>" placeholder="e.g. notifications@go4fin.com" style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>SMTP Password</label>
                <input name="smtp_password" type="password" value="<?=e($smtpPass)?>" placeholder="Enter SMTP password / App key" style="width: 100%; padding: 10px;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;">
                <div class="field">
                    <label>From Sender Email</label>
                    <input name="smtp_from_email" value="<?=e($smtpFromEmail)?>" placeholder="contact@go4fin.com" style="width: 100%; padding: 10px;">
                </div>
                <div class="field">
                    <label>From Sender Name</label>
                    <input name="smtp_from_name" value="<?=e($smtpFromName)?>" placeholder="GO4 Finance" style="width: 100%; padding: 10px;">
                </div>
            </div>
        </div>

        <!-- CUSTOMER EMI REPAYMENT CASHFREE GATEWAY CARD -->
        <div class="card" style="border: 1px solid rgba(16, 185, 129, 0.4); background: rgba(16, 185, 129, 0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="zap" style="color: #10b981;"></i> Customer EMI Gateway (Cashfree)
                </h3>
                <span class="badge badge-success" style="font-size: 0.72rem; padding: 4px 8px; letter-spacing: 0.5px;">RECOMMENDED</span>
            </div>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 16px; line-height: 1.4;">
                Configure <strong>Cashfree Payments</strong> for collecting <strong>Customer Loan EMI Repayments</strong>, down payments, and foreclosures directly into your merchant account via UPI, QR, NetBanking, and Cards.
            </p>

            <div class="field" style="margin-bottom: 14px;">
                <label style="font-weight: 700;">Active Customer EMI Gateway</label>
                <select name="emi_active_gateway" style="width: 100%; padding: 10px; font-weight: 600;">
                    <option value="cashfree" <?=$emiActiveGateway === 'cashfree' ? 'selected' : ''?>>⚡ Cashfree Payments (Active)</option>
                    <option value="payu" <?=$emiActiveGateway === 'payu' ? 'selected' : ''?>>PayU Money Gateway (Fallback)</option>
                </select>
            </div>

            <div style="padding: 14px; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; margin-bottom: 16px;">
                <h4 style="font-size: 0.85rem; font-weight: 800; color: #6ee7b7; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="shield" style="width: 15px; height: 15px;"></i> Cashfree PG Credentials (v2023-08-01)
                </h4>

                <div class="field" style="margin-bottom: 12px;">
                    <label>Cashfree App ID / Client ID *</label>
                    <input name="emi_cashfree_app_id" value="<?=e($emiCashfreeAppId)?>" placeholder="e.g. 102938484... or CF_TEST_..." style="width: 100%; padding: 10px; font-family: monospace;">
                </div>

                <div class="field" style="margin-bottom: 12px;">
                    <label>Cashfree Secret Key *</label>
                    <input name="emi_cashfree_secret_key" type="password" value="<?=e($emiCashfreeSecret)?>" placeholder="e.g. cfsk_ma_prod_... or cfsk_ma_test_..." style="width: 100%; padding: 10px; font-family: monospace;">
                </div>

                <div class="field" style="margin-bottom: 6px;">
                    <label>Cashfree Environment Mode</label>
                    <select name="emi_cashfree_env" style="width: 100%; padding: 10px;">
                        <option value="sandbox" <?=$emiCashfreeEnv === 'sandbox' ? 'selected' : ''?>>🧪 TEST / Sandbox Mode (sandbox.cashfree.com)</option>
                        <option value="production" <?=$emiCashfreeEnv === 'production' ? 'selected' : ''?>>🚀 LIVE / Production Mode (api.cashfree.com)</option>
                    </select>
                </div>
            </div>

            <!-- Optional PayU Fallback Details -->
            <details style="margin-bottom: 16px; background: rgba(15, 23, 42, 0.3); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 10px 12px;">
                <summary style="cursor: pointer; font-size: 0.8rem; font-weight: 700; color: #94a3b8;">
                    ⚙️ PayU Backup Gateway Settings (Click to expand)
                </summary>
                <div style="margin-top: 12px;">
                    <div class="field" style="margin-bottom: 10px;">
                        <label style="font-size: 0.78rem;">PayU Merchant Key</label>
                        <input name="emi_payu_key" value="<?=e($emiPayuKey)?>" placeholder="PayU Key" style="width: 100%; padding: 8px; font-size: 0.82rem;">
                    </div>
                    <div class="field" style="margin-bottom: 10px;">
                        <label style="font-size: 0.78rem;">PayU Salt</label>
                        <input name="emi_payu_salt" type="password" value="<?=e($emiPayuSalt)?>" placeholder="PayU Salt" style="width: 100%; padding: 8px; font-size: 0.82rem;">
                    </div>
                    <div class="field">
                        <label style="font-size: 0.78rem;">PayU Mode</label>
                        <select name="emi_payu_env" style="width: 100%; padding: 8px; font-size: 0.82rem;">
                            <option value="production" <?=$emiPayuEnv === 'production' ? 'selected' : ''?>>LIVE Production</option>
                            <option value="test" <?=$emiPayuEnv === 'test' ? 'selected' : ''?>>TEST Sandbox</option>
                        </select>
                    </div>
                </div>
            </details>

            <div style="background: rgba(59, 130, 246, 0.08); border: 1px dashed rgba(59, 130, 246, 0.35); border-radius: 8px; padding: 12px; font-size: 0.8rem; color: #94a3b8; line-height: 1.4;">
                <strong style="color: #60a5fa; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <i data-lucide="shield-check" style="width: 15px; height: 15px;"></i> Developer Wallet Gateway Protected
                </strong>
                Shop and staff wallet topup payments are routed directly to the <strong>Core Developer Gateway</strong> hardcoded in the backend. Admin cannot modify wallet gateway keys.
            </div>
        </div>


        <!-- POS ADDON LICENSE CONFIG CARD -->
        <div class="card" style="border: 1px solid rgba(245,158,11,0.4);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="shopping-cart" style="color: #f59e0b;"></i> Store POS Addon & Cashfree Gateway
                </h3>
                <span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid #10b981; font-weight: 800; font-size: 0.72rem; padding: 2px 8px; border-radius: 6px;">
                    ⭐ SUPERADMIN FREE
                </span>
            </div>

            <div style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); color: #60a5fa; padding: 12px 14px; border-radius: 10px; font-size: 0.82rem; line-height: 1.5; margin-bottom: 16px;">
                <strong>⚡ Rule Enforced:</strong> POS Terminal is completely <strong>FREE for Superadmin</strong>. For store/merchant accounts, POS remains locked until they pay online via Cashfree Payment Gateway.
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>Shop POS Activation Price (₹)</label>
                <input type="number" step="any" name="pos_activation_price" value="<?=e($posPrice)?>" placeholder="1999" style="width: 100%; padding: 10px; font-weight: 700; font-size: 1rem; color: #10b981;">
                <small class="muted" style="margin-top: 4px; display: block;">Price in INR charged to stores when unlocking POS via Cashfree (Default: ₹1999).</small>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>Global POS Addon Override (Fallback)</label>
                <select name="pos_addon_activated" style="width: 100%; padding: 10px;">
                    <option value="0" <?=$posActivated === '0' ? 'selected' : ''?>>🔒 Per-Store Locking Enforced (Requires ₹1999 payment)</option>
                    <option value="1" <?=$posActivated === '1' ? 'selected' : ''?>>✅ Globally Active (All stores allowed)</option>
                </select>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>Offline Developer License Key</label>
                <input name="pos_addon_api_key" value="<?=e($posApiKey)?>" placeholder="e.g. KKWEBMART-PREMIUIM-ADDON-2022" style="width: 100%; padding: 10px; font-weight: 700;">
                <small class="muted" style="margin-top: 4px; display: block;">Developer manual offline bypass key.</small>
            </div>
        </div>

        <!-- CREDIT BUREAU (TRANSUNION CIBIL) CARD -->
        <div class="card" style="border: 1px solid rgba(2, 132, 199, 0.4); background: rgba(2, 132, 199, 0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="shield-check" style="color: #0284c7;"></i> Credit Bureau (TransUnion CIBIL)
                </h3>
                <span class="badge" style="background: rgba(2, 132, 199, 0.2); color: #38bdf8; font-weight: 800; font-size: 0.72rem; padding: 2px 8px; border-radius: 6px;">
                    FINPAY ULTRA API
                </span>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>Bureau Integration Mode</label>
                <select name="bureau_test_mode" style="width: 100%; padding: 10px; font-weight: 700;">
                    <option value="0" <?=$bureauTestMode === '0' ? 'selected' : ''?>>🚀 Live Production Bureau Mode (Fetches Live CIBIL / TransUnion)</option>
                    <option value="1" <?=$bureauTestMode === '1' ? 'selected' : ''?>>🧪 Test / Sandbox Simulator Mode (₹0 Wallet Debit - No External API Hits)</option>
                </select>
                <small class="muted" style="margin-top: 4px; display: block;">Select Test Mode to simulate inquiries without hitting live FinPay Ultra servers.</small>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>FinPay Ultra Credit API Key</label>
                <input name="finpay_credit_api_key" value="<?=e($finpayApiKey)?>" placeholder="Enter FinPay API Key" style="width: 100%; padding: 10px; font-family: monospace;">
                <small class="muted" style="margin-top: 4px; display: block;">Endpoint: https://api.finpayultra.com/api/transunion-pdf</small>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>First-Time Borrower / New to Credit Policy</label>
                <select name="allow_new_to_credit_finance" style="width: 100%; padding: 10px; font-weight: 600;">
                    <option value="1" <?=$allowNtcFinance === '1' ? 'selected' : ''?>>✅ Allow Financing for New to Credit Applicants (Score -1 / NH, Clean Record)</option>
                    <option value="0" <?=$allowNtcFinance === '0' ? 'selected' : ''?>>🔒 Require Minimum Credit Score 600 (Strict)</option>
                </select>
                <small class="muted" style="margin-top: 4px; display: block;">When enabled, customers verified by CIBIL as having zero loan history (New to Credit) can be sanctioned for store financing.</small>
            </div>
        </div>

        <!-- THEME SELECTION CARD -->
        <div class="card">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="sun" style="color: var(--warning);"></i> ERP Theme Customization
            </h3>

            <div class="field" style="margin-bottom: 16px;">
                <label>Default Portal Theme</label>
                <select name="theme_mode" style="width: 100%; padding: 10px;">
                    <option value="light" <?=$themeMode === 'light' ? 'selected' : ''?>>Light Modern Theme</option>
                    <option value="dark" <?=$themeMode === 'dark' ? 'selected' : ''?>>Dark Glassmorphic Theme</option>
                </select>
            </div>
        </div>

    </div>

    <button type="submit" class="btn" style="padding: 14px 28px; font-size: 0.95rem; background: linear-gradient(135deg, var(--primary), #1d4ed8);">
        <i data-lucide="save"></i> Save Settings & SMTP Config
    </button>
</form>

<!-- TEST EMAIL SENDER BOX -->
<div class="card" style="margin-top: 24px; border: 1px dashed var(--primary); background: rgba(59,130,246,0.06);">
    <h4 style="font-weight: 800; font-size: 0.95rem; margin-bottom: 10px; color: var(--primary); display: flex; align-items: center; gap: 8px;">
        <i data-lucide="send"></i> Test SMTP Email Delivery
    </h4>
    <p class="muted" style="margin-bottom: 14px; font-size: 0.82rem;">Enter an email address to send a test email using your configured SMTP settings.</p>

    <form method="post" style="display: flex; gap: 10px; max-width: 500px;">
        <input type="hidden" name="action" value="test_smtp_email">
        <input type="email" name="test_email_address" placeholder="Enter recipient email..." required style="flex: 1; padding: 10px; border-radius: 8px;">
        <button type="submit" class="btn" style="background: var(--primary); padding: 10px 18px; font-weight: 700; white-space: nowrap;">
            📨 Send Test Email
        </button>
    </form>
</div>

<?php render_end(); ?>
