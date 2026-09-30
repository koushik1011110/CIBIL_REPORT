<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$p = db();
$u = u();
$userId = (int)($u['id'] ?? 1);
$shopId = (int)($_GET['id'] ?? 0);

if ($shopId <= 0) {
    header('Location: shops.php');
    exit;
}

// Fetch Shop Details
$stmt = $p->prepare('SELECT * FROM shops WHERE id = ?');
$stmt->execute([$shopId]);
$shop = $stmt->fetch();

if (!$shop) {
    header('Location: shops.php?msg=notfound');
    exit;
}

// Fetch Manager User
$uStmt = $p->prepare('SELECT id, name, email, status FROM users WHERE shop_id = ? AND role = "shop_admin" LIMIT 1');
$uStmt->execute([$shopId]);
$manager = $uStmt->fetch() ?: [];

$err = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($_POST['name'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $gstin         = trim($_POST['gstin'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $status        = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $posActive     = isset($_POST['pos_active']) ? (int)$_POST['pos_active'] : (int)($shop['pos_active'] ?? 0);
    $managerEmail  = trim($_POST['manager_email'] ?? $email);
    $resetPassword = trim($_POST['reset_password'] ?? '');

    if (empty($name)) {
        $err = 'Shop name is required.';
    } else {
        try {
            $logoSql = '';
            $logoParams = [];

            // Handle Logo Upload
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                    $logosDir = __DIR__ . '/../uploads/logos';
                    if (!is_dir($logosDir)) {
                        @mkdir($logosDir, 0777, true);
                    }
                    $filename = 'shop_' . $shopId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $logosDir . '/' . $filename)) {
                        $logoSql = ', logo = ?';
                        $logoParams[] = $filename;
                        $shop['logo'] = $filename;
                    }
                } else {
                    $err = 'Invalid logo format. Supported: JPG, PNG, WEBP, SVG.';
                }
            }

            if (empty($err)) {
                // Update shops table including POS Addon license status
                $upSql = "UPDATE shops SET name = ?, phone = ?, email = ?, gstin = ?, address = ?, status = ?, pos_active = ? {$logoSql} WHERE id = ?";
                $upParams = array_merge([$name, $phone, $email, $gstin, $address, $status, $posActive], $logoParams, [$shopId]);
                $p->prepare($upSql)->execute($upParams);

                // Update or Create Shop Admin user
                if (!empty($manager['id'])) {
                    if (!empty($resetPassword)) {
                        $passHash = password_hash($resetPassword, PASSWORD_DEFAULT);
                        $p->prepare('UPDATE users SET name = ?, email = ?, password = ?, status = ? WHERE id = ?')
                          ->execute([$name, $managerEmail ?: ('shop' . $shopId . '@store.local'), $passHash, $status, $manager['id']]);
                    } else {
                        $p->prepare('UPDATE users SET name = ?, email = ?, status = ? WHERE id = ?')
                          ->execute([$name, $managerEmail ?: ('shop' . $shopId . '@store.local'), $status, $manager['id']]);
                    }
                } else {
                    // Check if user with managerEmail exists
                    $existingByEmail = 0;
                    if (!empty($managerEmail)) {
                        $chk = $p->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                        $chk->execute([$managerEmail]);
                        $existingByEmail = (int)$chk->fetchColumn();
                    }
                    $passHash = password_hash(!empty($resetPassword) ? $resetPassword : '123456', PASSWORD_DEFAULT);
                    if ($existingByEmail > 0) {
                        $p->prepare("UPDATE users SET name = ?, shop_id = ?, password = ?, role = 'shop_admin', status = ? WHERE id = ?")
                          ->execute([$name, $shopId, $passHash, $status, $existingByEmail]);
                    } else {
                        $insUser = $p->prepare('INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, "shop_admin", ?)');
                        $insUser->execute([$shopId, $name, $managerEmail ?: ('shop' . $shopId . '@store.local'), $passHash, $status]);
                    }
                }

                log_audit(
                    'Merchant Details Updated',
                    'Shops',
                    "Superadmin ID {$userId} updated merchant details for #{$shopId} ({$name})",
                    $userId
                );

                header('Location: shops.php?msg=updated&shop_name=' . urlencode($name));
                exit;
            }

        } catch (Exception $e) {
            $err = 'Error updating merchant: ' . $e->getMessage();
        }
    }
}

// Fetch stats for this shop
$appCount = (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE shop_id = {$shopId}")->fetchColumn();
$totalFinanced = (float)$p->query("SELECT COALESCE(SUM(finance_amount), 0) FROM finance_applications WHERE shop_id = {$shopId}")->fetchColumn();

start('Edit Merchant Shop · ' . $shop['name']);
?>

<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div>
        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 6px;">
            <a href="shops.php" style="color: var(--primary); text-decoration: none;">← Back to Merchant Stores</a>
            <span>/</span>
            <span>Edit Merchant #<?=$shop['id']?></span>
        </div>
        <h2 style="font-size: 1.35rem; font-weight: 800; color: #fff;">Edit Merchant Shop Details</h2>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="shops.php" class="btn" style="background: rgba(255,255,255,0.08); border: 1px solid var(--border-color); color: #fff;">
            Cancel & Return
        </a>
    </div>
</div>

<?php if ($err): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        <strong>⚠ Error:</strong> <?=e($err)?>
    </div>
<?php endif; ?>

<!-- OVERVIEW STATS -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 22px;">
    <div class="card" style="padding: 16px; border-left: 4px solid var(--primary);">
        <div class="muted" style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700;">Wallet Balance</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #10b981; margin-top: 4px;"><?=money($shop['wallet_balance'])?></div>
    </div>
    <div class="card" style="padding: 16px; border-left: 4px solid var(--info);">
        <div class="muted" style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700;">Total Loans Processed</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #fff; margin-top: 4px;"><?=$appCount?> Loans</div>
    </div>
    <div class="card" style="padding: 16px; border-left: 4px solid var(--success);">
        <div class="muted" style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700;">Total Financed Volume</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #fff; margin-top: 4px;"><?=money($totalFinanced)?></div>
    </div>
</div>

<form method="post" enctype="multipart/form-data">
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
        
        <!-- MAIN STORE INFORMATION -->
        <div class="card" style="padding: 24px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="store" style="color: var(--primary);"></i> Store Identity & Contact Information
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Store / Merchant Name *</label>
                    <input type="text" name="name" value="<?=e($shop['name'])?>" required style="width: 100%; padding: 10px; font-weight: 700;">
                </div>
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Contact Phone Number *</label>
                    <input type="text" name="phone" value="<?=e($shop['phone'])?>" required style="width: 100%; padding: 10px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Official Email Address</label>
                    <input type="email" name="email" value="<?=e($shop['email'])?>" style="width: 100%; padding: 10px;">
                </div>
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">GSTIN Number</label>
                    <input type="text" name="gstin" value="<?=e($shop['gstin'])?>" placeholder="e.g. 18AAAAA0000A1Z5" style="width: 100%; padding: 10px; text-transform: uppercase;">
                </div>
            </div>

            <div class="field" style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Full Physical Address</label>
                <textarea name="address" rows="3" style="width: 100%; padding: 10px; line-height: 1.5;" placeholder="Shop No, Building, Street, City, State, PIN"><?=e($shop['address'])?></textarea>
            </div>

            <h4 style="font-size: 1rem; font-weight: 800; color: #fff; margin: 24px 0 16px 0; border-top: 1px solid var(--border-color); padding-top: 20px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="shield-check" style="color: #10b981;"></i> Merchant Portal Login Credentials
            </h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Merchant Login Email</label>
                    <input type="email" name="manager_email" value="<?=e($manager['email'] ?? $shop['email'])?>" style="width: 100%; padding: 10px;">
                    <small class="muted" style="font-size: 0.75rem; margin-top: 4px; display: block;">Used by merchant to log in to the Store Portal.</small>
                </div>
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; display: block;">Reset Password (Leave blank to keep unchanged)</label>
                    <input type="password" name="reset_password" placeholder="Enter new password to override" style="width: 100%; padding: 10px;">
                    <small class="muted" style="font-size: 0.75rem; margin-top: 4px; display: block;">Superadmin can set a new password anytime.</small>
                </div>
            </div>

            <div style="margin-top: 28px; display: flex; gap: 12px; justify-content: flex-end;">
                <a href="shops.php" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; padding: 11px 20px;">Cancel</a>
                <button type="submit" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; padding: 11px 26px;">
                    ✓ Save Merchant Changes
                </button>
            </div>
        </div>

        <!-- SIDEBAR: LOGO & STATUS -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            
            <!-- STATUS CARD -->
            <div class="card" style="padding: 20px;">
                <h4 style="font-size: 0.95rem; font-weight: 800; color: #fff; margin-bottom: 12px;">Merchant Account Status</h4>
                <div class="field">
                    <select name="status" style="width: 100%; padding: 10px; font-weight: 700; font-size: 0.95rem;">
                        <option value="active" <?=$shop['status']==='active'?'selected':''?>>🟢 ACTIVE (Allowed to create loans)</option>
                        <option value="inactive" <?=$shop['status']==='inactive'?'selected':''?>>🔴 INACTIVE (Account suspended)</option>
                    </select>
                </div>
                <p class="muted" style="font-size: 0.75rem; margin-top: 8px; line-height: 1.5;">
                    Inactive merchants cannot create new loan applications or access sensitive features.
                </p>
            </div>

            <!-- POS TERMINAL ADDON LICENSE -->
            <div class="card" style="padding: 20px; border: 1px solid <?=((int)$shop['pos_active'] === 1) ? 'rgba(16,185,129,0.4)' : 'rgba(245,158,11,0.4)'?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h4 style="font-size: 0.95rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <i data-lucide="shopping-cart" style="width: 16px; height: 16px; color: <?=((int)$shop['pos_active'] === 1) ? '#10b981' : '#f59e0b'?>;"></i>
                        POS Addon License
                    </h4>
                    <span class="badge" style="background: <?=((int)$shop['pos_active'] === 1) ? 'rgba(16,185,129,0.2); color:#10b981;' : 'rgba(245,158,11,0.2); color:#f59e0b;'?> font-weight: 800; font-size: 0.72rem; padding: 2px 8px; border-radius: 6px;">
                        <?=((int)$shop['pos_active'] === 1) ? '✓ UNLOCKED' : '🔒 LOCKED'?>
                    </span>
                </div>

                <div class="field" style="margin-bottom: 10px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; display: block; margin-bottom: 6px;">POS Terminal Status for Store</label>
                    <select name="pos_active" style="width: 100%; padding: 10px; font-weight: 700; font-size: 0.92rem;">
                        <option value="1" <?=((int)$shop['pos_active'] === 1) ? 'selected' : ''?>>🟢 ACTIVE (POS Billing Unlocked)</option>
                        <option value="0" <?=((int)$shop['pos_active'] === 0) ? 'selected' : ''?>>🔒 LOCKED (₹1,999 Payment Required)</option>
                    </select>
                </div>

                <div style="font-size: 0.76rem; color: #94a3b8; line-height: 1.6; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px;">
                    <div><strong>Addon Price:</strong> ₹<?=number_format($shop['pos_price'] ?? 1999, 2)?></div>
                    <?php if (!empty($shop['pos_activated_at'])): ?>
                        <div><strong>Activated At:</strong> <?=date('d M Y, h:i A', strtotime($shop['pos_activated_at']))?></div>
                    <?php endif; ?>
                    <?php if (!empty($shop['pos_order_id'])): ?>
                        <div><strong>Order ID:</strong> <?=e($shop['pos_order_id'])?></div>
                    <?php endif; ?>
                    <?php if (!empty($shop['pos_payment_ref'])): ?>
                        <div><strong>Payment Ref:</strong> <?=e($shop['pos_payment_ref'])?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LOGO CARD -->
            <div class="card" style="padding: 20px; text-align: center;">
                <h4 style="font-size: 0.95rem; font-weight: 800; color: #fff; margin-bottom: 14px;">Store Branding Logo</h4>
                
                <?php if (!empty($shop['logo']) && file_exists(__DIR__ . '/../uploads/logos/' . $shop['logo'])): ?>
                    <div style="margin-bottom: 14px;">
                        <img src="<?=url('/uploads/logos/' . $shop['logo'])?>" alt="Logo" style="max-height: 90px; max-width: 100%; object-fit: contain; border-radius: 8px; background: #fff; padding: 6px; border: 1px solid var(--border-color);">
                    </div>
                <?php else: ?>
                    <div style="width: 80px; height: 80px; border-radius: 12px; background: rgba(59,130,246,0.15); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 800; margin: 0 auto 14px auto;">
                        <?=strtoupper(substr($shop['name'], 0, 2))?>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 6px; display: block;">Upload New Logo (JPG, PNG, WEBP)</label>
                    <input type="file" name="logo" accept="image/*" style="font-size: 0.8rem; width: 100%;">
                </div>
            </div>

            <!-- AUDIT INFO -->
            <div class="card" style="padding: 16px; background: rgba(15,23,42,0.5);">
                <div style="font-size: 0.78rem; color: #94a3b8; line-height: 1.7;">
                    <div><strong>Created:</strong> <?=date('d M Y, h:i A', strtotime($shop['created_at']))?></div>
                    <div><strong>Store ID:</strong> #<?=$shop['id']?></div>
                </div>
            </div>

        </div>

    </div>
</form>

<?php render_end(); ?>
