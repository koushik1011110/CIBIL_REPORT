<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        header('Location: shops.php?msg=created');
        exit;
    }
}

$rows = $p->query('
    SELECT s.*, 
        (SELECT COUNT(*) FROM finance_applications WHERE shop_id = s.id) as app_count 
    FROM shops s 
    ORDER BY s.id DESC
')->fetchAll();

start('Merchant Shops Management');
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        <strong>✓ Store Created Successfully!</strong> New shop added and shop manager user account created.
    </div>
<?php endif; ?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">Registered Merchant Stores</h3>
        <p class="muted" style="margin-top: 2px;">Manage store outlets, wallet balances, and shop manager accounts</p>
    </div>
    <button class="btn" style="background: linear-gradient(135deg, var(--primary), #1d4ed8); padding: 10px 18px;" onclick="openShopModal()">
        <i data-lucide="plus-circle"></i> + Add New Shop
    </button>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">Shop ID</th>
                <th style="padding: 12px;">Store Name</th>
                <th style="padding: 12px;">Contact Phone</th>
                <th style="padding: 12px;">Email Address</th>
                <th style="padding: 12px;">Applications</th>
                <th style="padding: 12px;">Wallet Balance</th>
                <th style="padding: 12px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="7" style="text-align: center; padding: 20px;">No merchant shops found.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><strong>#<?=$r['id']?></strong></td>
                        <td style="padding: 12px;"><strong><?=e($r['name'])?></strong></td>
                        <td style="padding: 12px;"><?=e($r['phone'] ?: '-')?></td>
                        <td style="padding: 12px;"><?=e($r['email'] ?: '-')?></td>
                        <td style="padding: 12px;"><strong><?=$r['app_count']?> Applications</strong></td>
                        <td style="padding: 12px;"><strong style="color:var(--success);"><?=money($r['wallet_balance'])?></strong></td>
                        <td style="padding: 12px;">
                            <span class="badge badge-<?=$r['status'] === 'active' ? 'success' : 'danger'?>">
                                <?=strtoupper($r['status'])?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- POPUP MODAL FOR ADDING NEW SHOP -->
<div id="shopModalOverlay" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(8px); z-index: 999; justify-content: center; align-items: center; padding: 20px;">
    <div class="card" style="width: 100%; max-width: 500px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 16px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); animation: fadeIn 0.2s ease-in-out;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #fff;">+ Add New Merchant Shop</h3>
            <button onclick="closeShopModal()" style="background: transparent; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="post">
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
function openShopModal() {
    document.getElementById('shopModalOverlay').style.display = 'flex';
}
function closeShopModal() {
    document.getElementById('shopModalOverlay').style.display = 'none';
}
</script>

<?php render_end(); ?>
