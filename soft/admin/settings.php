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
        'theme_mode'          => $_POST['theme_mode'] ?? 'light',
        'emi_payu_key'        => trim($_POST['emi_payu_key'] ?? ''),
        'emi_payu_salt'       => trim($_POST['emi_payu_salt'] ?? ''),
        'emi_payu_env'        => $_POST['emi_payu_env'] ?? 'production',
        'pos_addon_activated' => $_POST['pos_addon_activated'] ?? '0',
        'pos_addon_api_key'   => trim($_POST['pos_addon_api_key'] ?? ''),
        'smtp_host'           => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'           => trim($_POST['smtp_port'] ?? '587'),
        'smtp_encryption'     => $_POST['smtp_encryption'] ?? 'tls',
        'smtp_username'       => trim($_POST['smtp_username'] ?? ''),
        'smtp_password'       => trim($_POST['smtp_password'] ?? ''),
        'smtp_from_email'     => trim($_POST['smtp_from_email'] ?? ''),
        'smtp_from_name'      => trim($_POST['smtp_from_name'] ?? '')
    ];

    foreach ($settings as $key => $val) {
        $stmt = $p->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $val]);
    }

    log_audit('Settings Update', 'Settings', 'Updated system settings, EMI gateway credentials, and SMTP email setup.', u()['id']);

    header('Location: settings.php?msg=saved');
    exit;
}

$themeMode    = get_setting('theme_mode', 'light');
$emiPayuKey   = get_setting('emi_payu_key', PAYU_MERCHANT_KEY);
$emiPayuSalt  = get_setting('emi_payu_salt', PAYU_SALT);
$emiPayuEnv   = get_setting('emi_payu_env', PAYU_ENV);
$posActivated = get_setting('pos_addon_activated', '0');
$posApiKey    = get_setting('pos_addon_api_key', '');

$smtpHost     = get_setting('smtp_host', '');
$smtpPort     = get_setting('smtp_port', '587');
$smtpEnc      = get_setting('smtp_encryption', 'tls');
$smtpUser     = get_setting('smtp_username', '');
$smtpPass     = get_setting('smtp_password', '');
$smtpFromEmail = get_setting('smtp_from_email', 'contact@go4fin.com');
$smtpFromName  = get_setting('smtp_from_name', 'GO4 Finance Private Limited');

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

        <!-- PAYU GATEWAY CARD -->
        <div class="card">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="credit-card" style="color: var(--success);"></i> PayU Payment Gateway Credentials
            </h3>

            <div class="field" style="margin-bottom: 14px;">
                <label>PayU Merchant Key</label>
                <input name="emi_payu_key" value="<?=e($emiPayuKey)?>" placeholder="Enter PayU Key" required style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>PayU Merchant Salt</label>
                <input name="emi_payu_salt" type="password" value="<?=e($emiPayuSalt)?>" placeholder="Enter PayU Salt" required style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>Payment Gateway Environment</label>
                <select name="emi_payu_env" style="width: 100%; padding: 10px;">
                    <option value="production" <?=$emiPayuEnv === 'production' ? 'selected' : ''?>>LIVE / Production Mode</option>
                    <option value="test" <?=$emiPayuEnv === 'test' ? 'selected' : ''?>>TEST / Sandbox Mode</option>
                </select>
            </div>
        </div>

        <!-- POS ADDON LICENSE CONFIG CARD -->
        <div class="card" style="border: 1px solid rgba(245,158,11,0.4);">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="key" style="color: #f59e0b;"></i> Premium POS Addon License
            </h3>

            <div class="field" style="margin-bottom: 16px;">
                <label>POS Addon Status</label>
                <select name="pos_addon_activated" style="width: 100%; padding: 10px;">
                    <option value="0" <?=$posActivated === '0' ? 'selected' : ''?>>🔒 Deactivated (Requires Developer API Verification)</option>
                    <option value="1" <?=$posActivated === '1' ? 'selected' : ''?>>✅ Activated (POS Billing & GST Enabled)</option>
                </select>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>POS License / API Key</label>
                <input name="pos_addon_api_key" value="<?=e($posApiKey)?>" placeholder="e.g. KKWEBMART-PREMIUIM-ADDON-2022" style="width: 100%; padding: 10px; font-weight: 700;">
                <small class="muted" style="margin-top: 4px; display: block;">Enter Developer POS API Key for activation.</small>
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
