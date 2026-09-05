<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff', 'customer');

$p = db();
$u = u();
$userId = (int)($u['id'] ?? 0);
$shopId = (int)($u['shop_id'] ?? 0);

if ($shopId === 0 && $u['role'] === 'superadmin') {
    $shopId = (int)($p->query("SELECT id FROM shops ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

// Fetch User Data
$userStmt = $p->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch();

// Fetch Shop Data
$shop = null;
if ($shopId > 0) {
    $shopStmt = $p->prepare("SELECT * FROM shops WHERE id = ?");
    $shopStmt->execute([$shopId]);
    $shop = $shopStmt->fetch();
}

$msg = '';
$err = '';

// Handle Shop & Profile Form Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. UPDATE SHOP PROFILE & LOGO FOR BILLING INVOICE
    if ($action === 'update_shop_profile' && $shopId > 0) {
        try {
            $shopName = trim($_POST['shop_name'] ?? '');
            $shopPhone = trim($_POST['shop_phone'] ?? '');
            $shopEmail = trim($_POST['shop_email'] ?? '');
            $gstin     = trim($_POST['gstin'] ?? '');
            $address   = trim($_POST['address'] ?? '');

            if (empty($shopName)) {
                throw new Exception("Shop Name cannot be empty.");
            }

            $logoFileName = $shop['logo'] ?? null;

            // Handle Shop Logo Upload
            if (isset($_FILES['shop_logo']) && $_FILES['shop_logo']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['shop_logo']['tmp_name'];
                $origName = $_FILES['shop_logo']['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
                if (!in_array($ext, $allowed)) {
                    throw new Exception("Invalid logo image format. Allowed formats: JPG, PNG, WEBP, SVG.");
                }

                $logosDir = __DIR__ . '/uploads/logos';
                if (!file_exists($logosDir)) {
                    @mkdir($logosDir, 0777, true);
                }

                $newFileName = 'shop_' . $shopId . '_' . time() . '.' . $ext;
                $targetPath = $logosDir . '/' . $newFileName;

                if (move_uploaded_file($tmpPath, $targetPath)) {
                    $logoFileName = $newFileName;
                } else {
                    throw new Exception("Failed to upload shop logo file.");
                }
            }

            // Update Shops table
            $upStmt = $p->prepare("
                UPDATE shops 
                SET name = ?, phone = ?, email = ?, gstin = ?, address = ?, logo = ? 
                WHERE id = ?
            ");
            $upStmt->execute([$shopName, $shopPhone, $shopEmail, $gstin, $address, $logoFileName, $shopId]);

            $msg = '✓ Shop Profile & Custom Billing Logo updated successfully!';
            
            // Refresh shop data
            $shopStmt->execute([$shopId]);
            $shop = $shopStmt->fetch();

        } catch (Exception $ex) {
            $err = $ex->getMessage();
        }
    }

    // 2. UPDATE USER PERSONAL DETAILS
    elseif ($action === 'update_user_info') {
        try {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if (empty($name) || empty($email)) {
                throw new Exception("Name and Email Address are required.");
            }

            // Check duplicate email
            $chk = $p->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $userId]);
            if ($chk->fetch()) {
                throw new Exception("Email address is already in use by another user.");
            }

            $p->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")->execute([$name, $email, $userId]);

            // Update session
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;

            $msg = '✓ Personal Account Info updated successfully!';
            
            $userStmt->execute([$userId]);
            $userData = $userStmt->fetch();

        } catch (Exception $ex) {
            $err = $ex->getMessage();
        }
    }

    // 3. CHANGE PASSWORD
    elseif ($action === 'change_password') {
        try {
            $currentPass = $_POST['current_password'] ?? '';
            $newPass     = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if (empty($currentPass) || empty($newPass)) {
                throw new Exception("Please fill out all password fields.");
            }

            if ($newPass !== $confirmPass) {
                throw new Exception("New Password and Confirm Password do not match.");
            }

            if (strlen($newPass) < 6) {
                throw new Exception("New Password must be at least 6 characters long.");
            }

            // Verify current password
            if (!password_verify($currentPass, $userData['password']) && md5($currentPass) !== $userData['password'] && $currentPass !== $userData['password']) {
                throw new Exception("Current Password is incorrect.");
            }

            $hashed = password_hash($newPass, PASSWORD_BCRYPT);
            $p->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $userId]);

            $msg = '✓ Password changed successfully!';

        } catch (Exception $ex) {
            $err = $ex->getMessage();
        }
    }
}

start('Account Profile & Shop Invoice Logo');
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

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px;">

    <!-- SHOP PROFILE & LOGO SETTINGS (FOR SHOP / ADMIN / STAFF) -->
    <?php if ($shop): ?>
        <div class="card" style="border: 1px solid rgba(59, 130, 246, 0.3);">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="store" style="color: var(--primary);"></i> Shop & Billing Invoice Logo
            </h3>
            <p class="muted" style="margin-bottom: 18px; font-size: 0.82rem;">Upload your store logo to appear on all POS GST Billing Invoices & Receipts.</p>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_shop_profile">

                <!-- CURRENT SHOP LOGO PREVIEW -->
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 16px; background: rgba(15,23,42,0.6); padding: 12px; border-radius: 10px; border: 1px solid var(--border-color);">
                    <?php if (!empty($shop['logo']) && file_exists(__DIR__ . '/uploads/logos/' . $shop['logo'])): ?>
                        <img src="<?=url('/uploads/logos/' . $shop['logo'])?>" alt="Shop Logo" style="height: 60px; width: 60px; object-fit: contain; border-radius: 8px; background: #fff; padding: 4px; border: 1px solid var(--border-color);">
                    <?php else: ?>
                        <img src="<?=url('/public/assets/images/logo.png')?>" alt="Default Logo" style="height: 60px; width: 60px; object-fit: contain; border-radius: 8px; background: #fff; padding: 4px;">
                    <?php endif; ?>
                    <div>
                        <strong style="color: #fff; font-size: 0.88rem; display: block; margin-bottom: 2px;">Billing Invoice Logo</strong>
                        <span class="muted" style="font-size: 0.75rem;">Supported: PNG, JPG, WEBP, SVG</span>
                    </div>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Upload New Shop Logo</label>
                    <input type="file" name="shop_logo" accept="image/*" style="padding: 6px;">
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Shop Store Name *</label>
                    <input type="text" name="shop_name" value="<?=e($shop['name'])?>" required>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Shop GSTIN (for GST Tax Invoices)</label>
                    <input type="text" name="gstin" value="<?=e($shop['gstin'])?>" placeholder="e.g. 18AABCU9603R1ZM" maxlength="15" style="text-transform: uppercase;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                    <div class="field">
                        <label>Store Phone</label>
                        <input type="text" name="shop_phone" value="<?=e($shop['phone'])?>">
                    </div>
                    <div class="field">
                        <label>Store Email</label>
                        <input type="email" name="shop_email" value="<?=e($shop['email'])?>">
                    </div>
                </div>

                <div class="field" style="margin-bottom: 18px;">
                    <label>Store Complete Address</label>
                    <textarea name="address" rows="3" placeholder="Full address to appear on billing invoice..."><?=e($shop['address'])?></textarea>
                </div>

                <button type="submit" class="btn" style="width: 100%; padding: 12px; font-weight: 800; background: linear-gradient(135deg, var(--primary), #2563eb);">
                    <i data-lucide="save"></i> Save Shop Logo & Billing Details
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- USER ACCOUNT & PASSWORD CHANGE -->
    <div>
        <!-- USER INFO -->
        <div class="card" style="margin-bottom: 24px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="user" style="color: var(--primary);"></i> Personal Profile Info
            </h3>

            <form method="POST">
                <input type="hidden" name="action" value="update_user_info">

                <div class="field" style="margin-bottom: 14px;">
                    <label>Full Name *</label>
                    <input type="text" name="name" value="<?=e($userData['name'])?>" required>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Email Address / Portal Login Username *</label>
                    <input type="email" name="email" value="<?=e($userData['email'])?>" required>
                </div>

                <div class="field" style="margin-bottom: 18px;">
                    <label>Account Role</label>
                    <input type="text" value="<?=strtoupper(e($userData['role']))?>" disabled style="opacity: 0.7; background: rgba(0,0,0,0.3);">
                </div>

                <button type="submit" class="btn" style="width: 100%; padding: 10px; font-weight: 700; background: var(--primary);">
                    <i data-lucide="save"></i> Update Profile Info
                </button>
            </form>
        </div>

        <!-- CHANGE PASSWORD -->
        <div class="card">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="lock" style="color: #f59e0b;"></i> Security & Change Password
            </h3>

            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="field" style="margin-bottom: 14px;">
                    <label>Current Password *</label>
                    <input type="password" name="current_password" placeholder="Enter current password" required>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>New Password *</label>
                    <input type="password" name="new_password" placeholder="Min 6 characters" required>
                </div>

                <div class="field" style="margin-bottom: 18px;">
                    <label>Confirm New Password *</label>
                    <input type="password" name="confirm_password" placeholder="Re-enter new password" required>
                </div>

                <button type="submit" class="btn" style="width: 100%; padding: 10px; font-weight: 700; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff;">
                    <i data-lucide="key"></i> Update Password
                </button>
            </form>
        </div>
    </div>

</div>

<?php render_end(); ?>
