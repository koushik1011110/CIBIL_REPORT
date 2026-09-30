<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();
$u = u();
$userId = (int)($u['id'] ?? 1);

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_shop';

    // TOGGLE POS TERMINAL ADDON FOR SHOP
    if ($action === 'toggle_pos') {
        $targetShopId = (int)($_POST['shop_id'] ?? 0);
        $setPos = (int)($_POST['pos_active'] ?? 0);
        if ($targetShopId > 0) {
            $sName = $p->query("SELECT name FROM shops WHERE id = {$targetShopId}")->fetchColumn() ?: ('Shop #' . $targetShopId);
            if ($setPos === 1) {
                activate_shop_pos($targetShopId, 'SUPERADMIN_MANUAL_' . time(), 'GRANTED_BY_ADMIN');
                log_audit('POS Addon Manual Activation', 'Shops', "Superadmin activated POS Addon for Shop #{$targetShopId} ({$sName})");
                header('Location: shops.php?msg=pos_activated&shop_name=' . urlencode($sName));
            } else {
                deactivate_shop_pos($targetShopId);
                log_audit('POS Addon Manual Deactivation', 'Shops', "Superadmin locked POS Addon for Shop #{$targetShopId} ({$sName})");
                header('Location: shops.php?msg=pos_locked&shop_name=' . urlencode($sName));
            }
            exit;
        }
    }

    // 1. DIRECT WALLET CREDIT / ADJUSTMENT BY ADMIN
    if ($action === 'update_wallet') {
        $targetShopId = (int)($_POST['shop_id'] ?? 0);
        $walletAction = $_POST['wallet_action'] ?? 'add_credit'; // 'add_credit', 'deduct_debit', 'set_exact'
        $amount = floatval($_POST['amount'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? 'Admin Manual Adjustment');

        if ($targetShopId > 0 && $amount >= 0) {
            $sStmt = $p->prepare('SELECT wallet_balance, name FROM shops WHERE id = ?');
            $sStmt->execute([$targetShopId]);
            $targetShop = $sStmt->fetch();

            if ($targetShop) {
                $curBal = floatval($targetShop['wallet_balance'] ?? 0);
                $newBal = $curBal;
                $txType = 'credit';
                $diff = $amount;

                if ($walletAction === 'add_credit') {
                    $newBal = $curBal + $amount;
                    $txType = 'credit';
                    $diff = $amount;
                } elseif ($walletAction === 'deduct_debit') {
                    $newBal = max(0, $curBal - $amount);
                    $txType = 'debit';
                    $diff = min($curBal, $amount);
                } elseif ($walletAction === 'set_exact') {
                    $newBal = max(0, $amount);
                    if ($newBal >= $curBal) {
                        $txType = 'credit';
                        $diff = $newBal - $curBal;
                    } else {
                        $txType = 'debit';
                        $diff = $curBal - $newBal;
                    }
                }

                // Update shops table balance
                $upStmt = $p->prepare('UPDATE shops SET wallet_balance = ? WHERE id = ?');
                $upStmt->execute([$newBal, $targetShopId]);

                // Record in wallet_transactions table
                $txnid = 'ADM' . time() . rand(100, 999);
                $fullRemarks = 'Admin Wallet Adjustment: ' . $remarks . ' (Bal: ₹' . number_format($curBal, 2) . ' ➔ ₹' . number_format($newBal, 2) . ')';
                try {
                    $insTx = $p->prepare('INSERT INTO wallet_transactions (user_id, shop_id, txnid, amount, type, status, payment_gateway, remarks) VALUES (?, ?, ?, ?, ?, "success", "Admin Manual", ?)');
                    $insTx->execute([$userId, $targetShopId, $txnid, $diff, $txType, $fullRemarks]);
                } catch (Exception $e) {
                    error_log('Error logging admin wallet transaction: ' . $e->getMessage());
                }

                header('Location: shops.php?msg=wallet_updated&shop_name=' . urlencode($targetShop['name']) . '&bal=' . urlencode(number_format($newBal, 2)));
                exit;
            }
        }
    }

    // 2. CREATE NEW SHOP
    if ($action === 'create_shop') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $balance = floatval($_POST['wallet_balance'] ?? 0);
        $status = $_POST['status'] ?? 'active';

        if (!empty($name)) {
            $stmt = $p->prepare('INSERT INTO shops (name, phone, email, wallet_balance, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $phone, $email, $balance, $status]);
            $shopId = (int)$p->lastInsertId();

            // Auto-create Shop Admin user account if email provided
            $shopAdminEmail = !empty($email) ? $email : ('shop' . $shopId . '@store.local');
            $checkUser = $p->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $checkUser->execute([$shopAdminEmail]);
            if (!$checkUser->fetchColumn()) {
                $passHash = password_hash('123456', PASSWORD_DEFAULT);
                $insUser = $p->prepare('INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, "shop_admin", "active")');
                $insUser->execute([$shopId, $name . ' Manager', $shopAdminEmail, $passHash]);
            }

            // Record initial wallet credit if balance > 0
            if ($balance > 0) {
                try {
                    $txnid = 'ADM' . time() . rand(100, 999);
                    $insTx = $p->prepare('INSERT INTO wallet_transactions (user_id, shop_id, txnid, amount, type, status, payment_gateway, remarks) VALUES (?, ?, ?, ?, "credit", "success", "Admin Manual", ?)');
                    $insTx->execute([$userId, $shopId, $txnid, $balance, 'Initial Store Wallet Balance']);
                } catch (Exception $e) {}
            }

            header('Location: shops.php?msg=created');
            exit;
        }
    }

    // 3. EDIT EXISTING SHOP DETAILS
    if ($action === 'edit_shop') {
        $shopId  = (int)($_POST['shop_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $gstin   = trim($_POST['gstin'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status  = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $resetPassword = trim($_POST['reset_password'] ?? '');

        if ($shopId > 0 && !empty($name)) {
            $logoSql = '';
            $logoParams = [];
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
                    }
                }
            }

            $upSql = "UPDATE shops SET name = ?, phone = ?, email = ?, gstin = ?, address = ?, status = ? {$logoSql} WHERE id = ?";
            $upParams = array_merge([$name, $phone, $email, $gstin, $address, $status], $logoParams, [$shopId]);
            $p->prepare($upSql)->execute($upParams);

            // Update associated manager account if exists
            $uStmt = $p->prepare("SELECT id FROM users WHERE shop_id = ? AND role = 'shop_admin' LIMIT 1");
            $uStmt->execute([$shopId]);
            $shopAdminId = (int)$uStmt->fetchColumn();

            if ($shopAdminId > 0) {
                if (!empty($resetPassword)) {
                    $passHash = password_hash($resetPassword, PASSWORD_DEFAULT);
                    $p->prepare("UPDATE users SET name = ?, email = ?, password = ?, status = ? WHERE id = ?")
                      ->execute([$name, !empty($email) ? $email : 'shop' . $shopId . '@store.local', $passHash, $status, $shopAdminId]);
                } else {
                    $p->prepare("UPDATE users SET name = ?, email = ?, status = ? WHERE id = ?")
                      ->execute([$name, !empty($email) ? $email : 'shop' . $shopId . '@store.local', $status, $shopAdminId]);
                }
            } else {
                // If shop admin user does not exist, check if user with same email exists
                $userByEmail = 0;
                if (!empty($email)) {
                    $chk = $p->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $chk->execute([$email]);
                    $userByEmail = (int)$chk->fetchColumn();
                }
                $passHash = password_hash(!empty($resetPassword) ? $resetPassword : '123456', PASSWORD_DEFAULT);
                if ($userByEmail > 0) {
                    $p->prepare("UPDATE users SET name = ?, shop_id = ?, password = ?, role = 'shop_admin', status = ? WHERE id = ?")
                      ->execute([$name, $shopId, $passHash, $status, $userByEmail]);
                } else {
                    $ins = $p->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, 'shop_admin', ?)");
                    $ins->execute([$shopId, $name, !empty($email) ? $email : 'shop' . $shopId . '@store.local', $passHash, $status]);
                }
            }

            log_audit(
                'Merchant Shop Updated',
                'Shops',
                "Superadmin updated merchant shop details for #{$shopId} ({$name})",
                $userId
            );

            header('Location: shops.php?msg=updated&shop_name=' . urlencode($name));
            exit;
        }
    }
}

$rows = $p->query('
    SELECT s.*, 
        (SELECT COUNT(*) FROM finance_applications WHERE shop_id = s.id) as app_count,
        (SELECT email FROM users WHERE shop_id = s.id AND role = "shop_admin" LIMIT 1) as manager_email
    FROM shops s 
    ORDER BY s.id DESC
')->fetchAll();

start('Merchant Shops & Wallet Management');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'created'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="check-circle"></i>
            <div><strong>Store Created Successfully!</strong> New shop added and shop manager user account created.</div>
        </div>
    <?php elseif ($_GET['msg'] === 'updated'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="check-circle"></i>
            <div><strong>Store Details Updated!</strong> Merchant details for <strong><?=e($_GET['shop_name'] ?? 'Store')?></strong> were saved successfully.</div>
        </div>
    <?php elseif ($_GET['msg'] === 'wallet_updated'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="wallet"></i>
            <div>
                <strong>Wallet Updated Successfully!</strong> Store <strong><?=e($_GET['shop_name'] ?? 'Store')?></strong> wallet balance is now <strong>₹<?=e($_GET['bal'] ?? '0.00')?></strong>. Transaction recorded in ledger.
            </div>
        </div>
    <?php elseif ($_GET['msg'] === 'pos_activated'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="check-circle"></i>
            <div><strong>POS Addon Activated!</strong> POS Billing Terminal unlocked for <strong><?=e($_GET['shop_name'] ?? 'Store')?></strong>.</div>
        </div>
    <?php elseif ($_GET['msg'] === 'pos_locked'): ?>
        <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid var(--warning); color: #f59e0b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="lock"></i>
            <div><strong>POS Addon Locked!</strong> POS Terminal has been locked for <strong><?=e($_GET['shop_name'] ?? 'Store')?></strong> until payment of ₹1999.</div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff;">Registered Merchant Stores & Wallets</h3>
        <p class="muted" style="margin-top: 2px;">Manage store outlets, directly credit/edit shop wallet balances, track applications, and control POS licenses</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="wallet.php" class="btn" style="background: rgba(139, 92, 246, 0.15); color: #a855f7; border: 1px solid rgba(139, 92, 246, 0.35); font-weight: 700;">
            <i data-lucide="wallet"></i> Global Wallet Ledger
        </a>
        <button class="btn" style="background: linear-gradient(135deg, var(--primary), #1d4ed8); padding: 10px 18px; font-weight: 700;" onclick="openShopModal()">
            <i data-lucide="plus-circle"></i> + Add New Shop
        </button>
    </div>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">Shop ID</th>
                <th style="padding: 12px;">Store Details & Logo</th>
                <th style="padding: 12px;">Contact Phone</th>
                <th style="padding: 12px;">Email & Manager Login</th>
                <th style="padding: 12px;">Applications</th>
                <th style="padding: 12px; min-width: 170px;">Wallet Balance</th>
                <th style="padding: 12px; text-align: center;">POS Terminal (₹1999)</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px; text-align: center;">Superadmin Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="9" style="text-align: center; padding: 20px;">No merchant shops found.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><strong>#<?=$r['id']?></strong></td>
                        <td style="padding: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if (!empty($r['logo']) && file_exists(__DIR__ . '/../uploads/logos/' . $r['logo'])): ?>
                                    <img src="<?=url('/uploads/logos/' . $r['logo'])?>" alt="Logo" style="width: 40px; height: 40px; object-fit: contain; border-radius: 8px; background: #fff; padding: 2px; border: 1px solid var(--border-color); flex-shrink: 0;">
                                <?php else: ?>
                                    <div style="width: 40px; height: 40px; border-radius: 8px; background: rgba(59,130,246,0.15); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem; flex-shrink: 0;">
                                        <?=strtoupper(substr($r['name'], 0, 2))?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <strong style="color: #fff; font-size: 0.95rem; display: block;"><?=e($r['name'])?></strong>
                                    <?php if (!empty($r['gstin'])): ?>
                                        <span style="font-size: 0.72rem; color: #94a3b8; font-family: monospace;">GSTIN: <?=e($r['gstin'])?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['address'])): ?>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?=e($r['address'])?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 12px;"><strong><?=e($r['phone'] ?: '-')?></strong></td>
                        <td style="padding: 12px;">
                            <?=e($r['email'] ?: '-')?><br>
                            <span style="font-size: 0.73rem; color: #94a3b8;">Login: <?=e($r['manager_email'] ?? $r['email'])?></span>
                        </td>
                        <td style="padding: 12px;"><strong><?=$r['app_count']?> Applications</strong></td>
                        <td style="padding: 12px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                <strong style="color: #10b981; font-size: 1.05rem;"><?=money($r['wallet_balance'])?></strong>
                                <button type="button" class="btn" style="padding: 4px 8px; font-size: 0.74rem; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" onclick='openWalletModal(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8")?>)'>
                                    <i data-lucide="plus" style="width: 11px; height: 11px;"></i> Credit
                                </button>
                            </div>
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <?php if ((int)($r['pos_active'] ?? 0) === 1): ?>
                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; font-weight: 700; padding: 4px 8px; border-radius: 6px; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px;">
                                    <i data-lucide="check-circle" style="width: 12px; height: 12px;"></i> Active
                                </span>
                                <form method="POST" style="margin-top: 5px;" onsubmit="return confirm('Lock POS terminal for this store?');">
                                    <input type="hidden" name="action" value="toggle_pos">
                                    <input type="hidden" name="shop_id" value="<?=$r['id']?>">
                                    <input type="hidden" name="pos_active" value="0">
                                    <button type="submit" style="background: none; border: none; color: #ef4444; font-size: 0.72rem; cursor: pointer; text-decoration: underline;">🔒 Lock POS</button>
                                </form>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4); font-weight: 700; padding: 4px 8px; border-radius: 6px; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px;">
                                    <i data-lucide="lock" style="width: 12px; height: 12px;"></i> Locked (₹1999)
                                </span>
                                <form method="POST" style="margin-top: 5px;">
                                    <input type="hidden" name="action" value="toggle_pos">
                                    <input type="hidden" name="shop_id" value="<?=$r['id']?>">
                                    <input type="hidden" name="pos_active" value="1">
                                    <button type="submit" style="background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #10b981; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; cursor: pointer;">⚡ Free Unlock</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <span class="badge badge-<?=$r['status'] === 'active' ? 'success' : 'danger'?>">
                                <?=strtoupper($r['status'])?>
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <div style="display: flex; gap: 6px; justify-content: center; flex-wrap: wrap;">
                                <button type="button" class="btn" style="padding: 6px 11px; font-size: 0.78rem; background: linear-gradient(135deg, var(--primary), #1d4ed8); color: #fff; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;" onclick='openEditModal(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8")?>)'>
                                    <i data-lucide="edit" style="width: 12px; height: 12px;"></i> Edit Details
                                </button>
                                <button type="button" class="btn" style="padding: 6px 9px; font-size: 0.78rem; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;" onclick='openWalletModal(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8")?>)'>
                                    <i data-lucide="wallet" style="width: 12px; height: 12px;"></i> Wallet
                                </button>
                                <a href="shop-edit.php?id=<?=$r['id']?>" class="btn" style="padding: 6px 9px; font-size: 0.78rem; background: rgba(255,255,255,0.06); color: var(--text-muted); border: 1px solid var(--border-color); border-radius: 6px;" title="Full Page Edit">
                                    <i data-lucide="external-link" style="width: 12px; height: 12px;"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ========================================================================= -->
<!-- POPUP MODAL FOR EDITING MERCHANT DETAILS                                  -->
<!-- ========================================================================= -->
<div id="editShopModalOverlay" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(8px); z-index: 9999; justify-content: center; align-items: center; padding: 20px;">
    <div class="card" style="width: 100%; max-width: 580px; background: #0f172a; border: 1.5px solid rgba(59, 130, 246, 0.4); border-radius: 18px; padding: 26px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.85); animation: fadeIn 0.2s ease-in-out; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="background: rgba(59, 130, 246, 0.15); color: var(--primary); padding: 8px; border-radius: 10px; display: inline-flex;">
                    <i data-lucide="edit" style="width: 20px; height: 20px;"></i>
                </span>
                <div>
                    <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff; margin: 0;">Edit Merchant Details</h3>
                    <div style="font-size: 0.8rem; color: #94a3b8;" id="editModalShopTitle">Shop Name</div>
                </div>
            </div>
            <button type="button" onclick="closeEditModal()" style="background: transparent; border: none; color: #94a3b8; font-size: 1.4rem; cursor: pointer; padding: 4px;">✕</button>
        </div>

        <form method="post" enctype="multipart/form-data" id="editShopForm">
            <input type="hidden" name="action" value="edit_shop">
            <input type="hidden" name="shop_id" id="editModalShopId" value="">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.82rem; margin-bottom: 6px; display: block;">Store / Merchant Name *</label>
                    <input type="text" name="name" id="editModalName" required style="width: 100%; padding: 10px; font-weight: 700; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;">
                </div>
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.82rem; margin-bottom: 6px; display: block;">Contact Phone *</label>
                    <input type="text" name="phone" id="editModalPhone" required style="width: 100%; padding: 10px; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.82rem; margin-bottom: 6px; display: block;">Official Email</label>
                    <input type="email" name="email" id="editModalEmail" style="width: 100%; padding: 10px; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;">
                </div>
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.82rem; margin-bottom: 6px; display: block;">GSTIN Number</label>
                    <input type="text" name="gstin" id="editModalGstin" placeholder="e.g. 18AAAAA0000A1Z5" style="width: 100%; padding: 10px; text-transform: uppercase; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;">
                </div>
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.82rem; margin-bottom: 6px; display: block;">Store Physical Address</label>
                <textarea name="address" id="editModalAddress" rows="2" style="width: 100%; padding: 10px; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div class="field">
                    <label style="font-weight: 700; font-size: 0.82rem; margin-bottom: 6px; display: block;">Store Status</label>
                    <select name="status" id="editModalStatus" style="width: 100%; padding: 10px; font-weight: 700; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;">
                        <option value="active">🟢 Active</option>
                        <option value="inactive">🔴 Inactive</option>
                    </select>
                </div>
                <div class="field">
                    <label style="font-size: 0.82rem; font-weight: 700; margin-bottom: 6px; display: block;">Upload New Logo</label>
                    <input type="file" name="logo" accept="image/*" style="font-size: 0.78rem; width: 100%;">
                </div>
            </div>

            <div class="field" style="margin-bottom: 22px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px;">
                <label style="font-size: 0.82rem; font-weight: 700; color: #f59e0b; margin-bottom: 6px; display: block;">
                    🔑 Reset Merchant Portal Login Password
                </label>
                <input type="password" name="reset_password" placeholder="Leave empty to keep existing password" style="width: 100%; padding: 9px; font-size: 0.85rem; background: rgba(2,6,23,0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff;">
                <small class="muted" style="font-size: 0.72rem; margin-top: 4px; display: block;">Superadmin can set a new login password for this merchant anytime.</small>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn" onclick="closeEditModal()" style="background: rgba(255,255,255,0.1); color: #fff; padding: 10px 18px;">Cancel</button>
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #1d4ed8); color: #fff; font-weight: 800; padding: 10px 22px;">
                    ✓ Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- POPUP MODAL FOR DIRECT WALLET CREDIT / ADJUSTMENT                         -->
<!-- ========================================================================= -->
<div id="walletModalOverlay" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(8px); z-index: 9999; justify-content: center; align-items: center; padding: 20px;">
    <div class="card" style="width: 100%; max-width: 520px; background: #0f172a; border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 18px; padding: 26px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.85); animation: fadeIn 0.2s ease-in-out;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 8px; border-radius: 10px; display: inline-flex;">
                    <i data-lucide="wallet" style="width: 20px; height: 20px;"></i>
                </span>
                <div>
                    <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff; margin: 0;">Edit Shop Wallet Balance</h3>
                    <div style="font-size: 0.8rem; color: #94a3b8;" id="modalShopTitle">Shop Name</div>
                </div>
            </div>
            <button type="button" onclick="closeWalletModal()" style="background: transparent; border: none; color: #94a3b8; font-size: 1.4rem; cursor: pointer; padding: 4px;">✕</button>
        </div>

        <form method="post" id="walletAdjustForm">
            <input type="hidden" name="action" value="update_wallet">
            <input type="hidden" name="shop_id" id="modalShopId" value="">

            <!-- CURRENT BALANCE BANNER -->
            <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.85rem; color: #94a3b8; font-weight: 600;">Current Available Balance:</span>
                <strong style="color: #10b981; font-size: 1.35rem;" id="modalCurrentBalance">₹0.00</strong>
            </div>

            <!-- ACTION MODE -->
            <div class="field" style="margin-bottom: 16px;">
                <label style="font-size: 0.82rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; display: block;">Select Adjustment Action *</label>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                    <label style="cursor: pointer; background: rgba(16, 185, 129, 0.1); border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 10px; padding: 10px; text-align: center; color: #10b981; font-weight: 700; font-size: 0.82rem;">
                        <input type="radio" name="wallet_action" value="add_credit" checked onchange="calcNewBalance()" style="margin-right: 4px;">
                        ➕ Add Credit
                    </label>
                    <label style="cursor: pointer; background: rgba(239, 68, 68, 0.08); border: 1.5px solid rgba(239, 68, 68, 0.35); border-radius: 10px; padding: 10px; text-align: center; color: #ef4444; font-weight: 700; font-size: 0.82rem;">
                        <input type="radio" name="wallet_action" value="deduct_debit" onchange="calcNewBalance()" style="margin-right: 4px;">
                        ➖ Deduct Debit
                    </label>
                    <label style="cursor: pointer; background: rgba(59, 130, 246, 0.08); border: 1.5px solid rgba(59, 130, 246, 0.35); border-radius: 10px; padding: 10px; text-align: center; color: #60a5fa; font-weight: 700; font-size: 0.82rem;">
                        <input type="radio" name="wallet_action" value="set_exact" onchange="calcNewBalance()" style="margin-right: 4px;">
                        ⚙️ Set Exact
                    </label>
                </div>
            </div>

            <!-- AMOUNT INPUT & PRESETS -->
            <div class="field" style="margin-bottom: 16px;">
                <label style="font-size: 0.82rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; display: block;" id="modalAmountLabel">Amount to Credit (₹) *</label>
                <input type="number" name="amount" id="modalAmountInput" min="1" step="0.01" value="500" required oninput="calcNewBalance()" style="width: 100%; font-size: 1.25rem; font-weight: 800; color: #fff; padding: 12px; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 10px;">
                
                <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setModalAmount(100)">+₹100</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setModalAmount(500)">+₹500</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setModalAmount(1000)">+₹1,000</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setModalAmount(2500)">+₹2,500</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setModalAmount(5000)">+₹5,000</button>
                </div>
            </div>

            <!-- LIVE BALANCE PREVIEW -->
            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.85rem; color: #cbd5e1; font-weight: 600;">Projected New Balance:</span>
                <strong style="color: #10b981; font-size: 1.3rem;" id="modalNewBalance">₹500.00</strong>
            </div>

            <!-- REMARKS INPUT -->
            <div class="field" style="margin-bottom: 22px;">
                <label style="font-size: 0.82rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; display: block;">Adjustment Note / Remarks</label>
                <input type="text" name="remarks" id="modalRemarksInput" placeholder="e.g. Approved Credit Topup by Superadmin" value="Manual Topup by Superadmin" style="width: 100%; padding: 10px; background: rgba(2, 6, 23, 0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff; font-size: 0.88rem;">
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn" onclick="closeWalletModal()" style="background: rgba(255,255,255,0.1); color: #fff; padding: 10px 18px;">Cancel</button>
                <button type="submit" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; padding: 10px 22px; display: inline-flex; align-items: center; gap: 6px;">
                    <i data-lucide="check"></i> ✓ Apply Wallet Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- POPUP MODAL FOR ADDING NEW SHOP                                           -->
<!-- ========================================================================= -->
<div id="shopModalOverlay" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(8px); z-index: 999; justify-content: center; align-items: center; padding: 20px;">
    <div class="card" style="width: 100%; max-width: 500px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 16px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); animation: fadeIn 0.2s ease-in-out;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #fff;">+ Add New Merchant Shop</h3>
            <button onclick="closeShopModal()" style="background: transparent; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="create_shop">

            <div class="field" style="margin-bottom: 14px;">
                <label>Shop / Store Name *</label>
                <input name="name" placeholder="e.g. Guwahati Mobile Store" required style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>Phone Number *</label>
                <input name="phone" placeholder="e.g. 9876543210" required style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>Store Email Address</label>
                <input name="email" type="email" placeholder="store@example.com" style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>Initial Wallet Balance (₹)</label>
                <input name="wallet_balance" type="number" step="0.01" value="0.00" style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 20px;">
                <label>Status</label>
                <select name="status" style="width: 100%; padding: 10px;">
                    <option value="active">ACTIVE</option>
                    <option value="inactive">INACTIVE</option>
                </select>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn" onclick="closeShopModal()" style="background: rgba(255,255,255,0.1); color: #fff;">Cancel</button>
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--success), #059669); color: #fff;">+ Create Shop</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentShopBal = 0;

function openEditModal(shop) {
    document.getElementById('editModalShopId').value = shop.id;
    document.getElementById('editModalShopTitle').textContent = '#' + shop.id + ' · ' + shop.name;
    document.getElementById('editModalName').value = shop.name || '';
    document.getElementById('editModalPhone').value = shop.phone || '';
    document.getElementById('editModalEmail').value = shop.email || '';
    document.getElementById('editModalGstin').value = shop.gstin || '';
    document.getElementById('editModalAddress').value = shop.address || '';
    document.getElementById('editModalStatus').value = shop.status || 'active';
    document.getElementById('editShopModalOverlay').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editShopModalOverlay').style.display = 'none';
}

function openWalletModal(shop) {
    document.getElementById('modalShopId').value = shop.id;
    document.getElementById('modalShopTitle').textContent = '#' + shop.id + ' · ' + shop.name;
    currentShopBal = parseFloat(shop.wallet_balance || 0);
    document.getElementById('modalCurrentBalance').textContent = '₹' + currentShopBal.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    document.getElementById('modalAmountInput').value = '500';
    document.querySelector('input[name="wallet_action"][value="add_credit"]').checked = true;
    calcNewBalance();
    document.getElementById('walletModalOverlay').style.display = 'flex';
}

function closeWalletModal() {
    document.getElementById('walletModalOverlay').style.display = 'none';
}

function setModalAmount(amt) {
    document.getElementById('modalAmountInput').value = amt;
    calcNewBalance();
}

function calcNewBalance() {
    const actionEl = document.querySelector('input[name="wallet_action"]:checked');
    const action = actionEl ? actionEl.value : 'add_credit';
    const amt = parseFloat(document.getElementById('modalAmountInput').value) || 0;
    const label = document.getElementById('modalAmountLabel');
    
    let newBal = currentShopBal;
    if (action === 'add_credit') {
        newBal = currentShopBal + amt;
        if (label) label.textContent = 'Amount to Add (₹) *';
    } else if (action === 'deduct_debit') {
        newBal = Math.max(0, currentShopBal - amt);
        if (label) label.textContent = 'Amount to Deduct (₹) *';
    } else if (action === 'set_exact') {
        newBal = Math.max(0, amt);
        if (label) label.textContent = 'Set New Exact Balance (₹) *';
    }
    
    document.getElementById('modalNewBalance').textContent = '₹' + newBal.toLocaleString('en-IN', { minimumFractionDigits: 2 });
}

function openShopModal() {
    document.getElementById('shopModalOverlay').style.display = 'flex';
}
function closeShopModal() {
    document.getElementById('shopModalOverlay').style.display = 'none';
}
</script>

<?php render_end(); ?>
