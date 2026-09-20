<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$p = db();
$msg = '';
$err = '';

// Handle Add User
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'staff');
    $shopId = (int)($_POST['shop_id'] ?? 0);

    if (empty($email) && !empty($mobile)) {
        $email = $mobile . '@go4fin.local';
    }

    if (empty($name) || empty($password)) {
        $err = "Name and Password are required.";
    } elseif (empty($email)) {
        $err = "Please provide an Email Address or Mobile Number.";
    } else {
        // Check duplicate
        $chk = $p->prepare("SELECT id FROM users WHERE email = ? AND email != ''");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $err = "A user with this Email ({$email}) already exists.";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $p->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $ins->execute([$shopId ?: null, $name, $email, $hash, $role]);
            $msg = "User '{$name}' created successfully as " . strtoupper($role) . "!";
            log_audit('User Created', 'Users', "Created user {$name} ({$role})", u()['id']);
        }
    }
}

// Fetch Users
$stmt = $p->query('
    SELECT u.*, s.name as shop_name, s.phone as shop_phone, s.gstin as shop_gstin 
    FROM users u 
    LEFT JOIN shops s ON s.id = u.shop_id 
    ORDER BY u.id DESC
');
$users = $stmt->fetchAll();

// Fetch Shops for Dropdown
$shops = $p->query('SELECT id, name, gstin, phone FROM shops ORDER BY name ASC')->fetchAll();

// Role counts
$countSuper = 0; $countShopAdmin = 0; $countStaff = 0; $countCustomer = 0;
foreach ($users as $u) {
    if ($u['role'] === 'superadmin') $countSuper++;
    elseif ($u['role'] === 'shop_admin') $countShopAdmin++;
    elseif ($u['role'] === 'staff') $countStaff++;
    elseif ($u['role'] === 'customer') $countCustomer++;
}

start('User Accounts & Roles Management');
?>

<div style="max-width: 1200px; margin: 0 auto;">

    <!-- TOP HEADER -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin-bottom: 4px;">User Accounts & Role Permissions</h2>
            <p class="muted" style="font-size: 0.88rem;">Manage system administrators, store managers, counter staff, and customer accounts</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" onclick="document.getElementById('addUserModal').style.display='flex'" class="btn btn-primary" style="font-size: 0.88rem;">
                <i data-lucide="user-plus"></i> Add New User
            </button>
            <a href="<?=url('/admin/menu.php')?>" class="btn" style="background: rgba(255,255,255,0.08); border: 1px solid var(--border-color); font-size: 0.88rem;">
                <i data-lucide="layout-grid"></i> Menu Hub
            </a>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; background: rgba(16,185,129,0.15); border: 1px solid #10b981; color: #10b981; padding: 12px 16px; border-radius: 8px; font-weight: 600;">
            ✓ <?=e($msg)?>
        </div>
    <?php endif; ?>

    <?php if($err): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px; background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #ef4444; padding: 12px 16px; border-radius: 8px; font-weight: 600;">
            ⚠️ <?=e($err)?>
        </div>
    <?php endif; ?>

    <!-- STATS PILLS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px;">
        <div class="card" style="padding: 16px; border-left: 3px solid var(--primary);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Total Users</div>
            <div style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin-top: 2px;"><?=count($users)?> Users</div>
        </div>
        <div class="card" style="padding: 16px; border-left: 3px solid #ef4444;">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Super Admins</div>
            <div style="font-size: 1.4rem; font-weight: 800; color: #ef4444; margin-top: 2px;"><?=$countSuper?> Admins</div>
        </div>
        <div class="card" style="padding: 16px; border-left: 3px solid #8b5cf6;">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Store Admins</div>
            <div style="font-size: 1.4rem; font-weight: 800; color: #8b5cf6; margin-top: 2px;"><?=$countShopAdmin?> Managers</div>
        </div>
        <div class="card" style="padding: 16px; border-left: 3px solid #10b981;">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Store Staff</div>
            <div style="font-size: 1.4rem; font-weight: 800; color: #10b981; margin-top: 2px;"><?=$countStaff?> Staff</div>
        </div>
    </div>

    <!-- USERS DIRECTORY TABLE CARD -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="font-weight: 800; font-size: 1rem; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i data-lucide="users" style="width: 18px; height: 18px; color: var(--primary);"></i> User Accounts Directory
            </div>
            <div style="max-width: 280px; width: 100%;">
                <input type="text" id="userFilterInput" onkeyup="filterUserTable()" placeholder="Search user name, email, shop..." style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border-radius: 6px;">
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table" id="usersTable" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                <thead>
                    <tr style="background: rgba(15, 23, 42, 0.6); color: var(--text-muted); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px;">
                        <th style="padding: 12px 18px; text-align: left;">User Profile</th>
                        <th style="padding: 12px 18px; text-align: left;">Contact Details</th>
                        <th style="padding: 12px 18px; text-align: left;">Role Access</th>
                        <th style="padding: 12px 18px; text-align: left;">Associated Merchant Store</th>
                        <th style="padding: 12px 18px; text-align: left;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($users)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 24px;" class="muted">No user accounts found.</td></tr>
                    <?php else: ?>
                        <?php foreach($users as $uRow): 
                            $uInit = strtoupper(substr($uRow['name'] ?: 'U', 0, 2));
                            $roleClass = 'info';
                            if ($uRow['role'] === 'superadmin') $roleClass = 'danger';
                            elseif ($uRow['role'] === 'shop_admin') $roleClass = 'primary';
                            elseif ($uRow['role'] === 'staff') $roleClass = 'success';
                            elseif ($uRow['role'] === 'customer') $roleClass = 'warning';
                        ?>
                            <tr style="border-bottom: 1px solid var(--border-color);" class="user-table-row">
                                <td style="padding: 12px 18px;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.85rem; flex-shrink: 0;">
                                            <?=$uInit?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; color: var(--text-main);"><?=e($uRow['name'])?></div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">ID: #<?=$uRow['id']?> · Created <?=date('d M Y', strtotime($uRow['created_at'] ?? 'now'))?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 12px 18px;">
                                    <div style="font-weight: 600; color: var(--text-main);"><?=e($uRow['email'] ?: 'No email')?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?=e($uRow['mobile'] ?? '-')?></div>
                                </td>
                                <td style="padding: 12px 18px;">
                                    <span class="badge badge-<?=$roleClass?>" style="font-size: 0.72rem; text-transform: uppercase;">
                                        <?=str_replace('_', ' ', $uRow['role'])?>
                                    </span>
                                </td>
                                <td style="padding: 12px 18px;">
                                    <?php if(!empty($uRow['shop_name'])): ?>
                                        <div style="font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 6px;">
                                            <i data-lucide="store" style="width: 14px; height: 14px;"></i> <?=e($uRow['shop_name'])?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?=!empty($uRow['shop_gstin']) ? 'GST: '.e($uRow['shop_gstin']) : (!empty($uRow['shop_phone']) ? e($uRow['shop_phone']) : 'Store #'.e($uRow['shop_id']))?></div>
                                    <?php else: ?>
                                        <span class="muted" style="font-style: italic;">All Stores / Master ERP</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 18px;">
                                    <span class="badge badge-<?=$uRow['status'] === 'active' ? 'success' : 'danger'?>" style="font-size: 0.7rem;">
                                        <?=strtoupper($uRow['status'] ?? 'active')?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ADD USER MODAL -->
<div id="addUserModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(5px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div class="card" style="max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border-color);">
            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="user-plus" style="color: var(--primary);"></i> Create New User Account
            </h3>
            <button type="button" onclick="document.getElementById('addUserModal').style.display='none'" style="background: transparent; border: none; font-size: 1.3rem; color: var(--text-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="add_user">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px;">Full Name *</label>
                <input type="text" name="name" required placeholder="e.g. Rahul Sharma" style="width: 100%;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px;">Email Address</label>
                    <input type="email" name="email" placeholder="rahul@store.com" style="width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px;">Mobile Number</label>
                    <input type="tel" name="mobile" placeholder="9876543210" style="width: 100%;">
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px;">Login Password *</label>
                <input type="password" name="password" required placeholder="Minimum 6 characters" style="width: 100%;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px;">Role Access *</label>
                    <select name="role" required style="width: 100%;">
                        <option value="staff">Store Staff</option>
                        <option value="shop_admin">Store Admin / Manager</option>
                        <option value="superadmin">Super Administrator</option>
                        <option value="customer">Customer / Borrower</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px;">Assigned Store</label>
                    <select name="shop_id" style="width: 100%;">
                        <option value="">-- Master / All Stores --</option>
                        <?php foreach($shops as $sh): ?>
                            <option value="<?=$sh['id']?>"><?=e($sh['name'])?> (ID: #<?=$sh['id']?><?=!empty($sh['gstin']) ? ' · ' . e($sh['gstin']) : ''?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="document.getElementById('addUserModal').style.display='none'" class="btn" style="background: rgba(255,255,255,0.08);">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User Account</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterUserTable() {
    const q = document.getElementById('userFilterInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.user-table-row');
    rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
    });
}
</script>

<?php render_end(); ?>
