<?php 
require_once __DIR__.'/../includes/layout.php';
role('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = db();
    $shopId = (int)(u()['shop_id'] ?: 1);
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pan = trim($_POST['pan'] ?? '');
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $address = trim($_POST['address'] ?? '');

    // 1. Insert into customers table
    $s = $p->prepare('INSERT INTO customers(shop_id, name, mobile, email, pan, dob, address) VALUES(?, ?, ?, ?, ?, ?, ?)');
    $s->execute([$shopId, $name, $mobile, $email, $pan, $dob, $address]);

    // 2. Auto-generate Customer Portal User Account
    $userEmail = !empty($email) ? $email : ($mobile . '@customer.local');
    $checkUser = $p->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $checkUser->execute([$userEmail]);

    if (!$checkUser->fetchColumn()) {
        $passHash = password_hash('123456', PASSWORD_DEFAULT);
        $insUser = $p->prepare('INSERT INTO users(shop_id, name, email, password, role, status) VALUES(?, ?, ?, ?, "customer", "active")');
        $insUser->execute([$shopId, $name, $userEmail, $passHash]);
    }

    header('Location: customers.php');
    exit;
}

start('Add Customer');
?>
<div class="card">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px;">Add New Customer</h3>
    <form class="form" method="post">
        <div class="form-grid">
            <div class="field">
                <label>Customer Full Name *</label>
                <input name="name" placeholder="e.g. John Doe" required>
            </div>
            <div class="field">
                <label>Mobile Number * (Used for Portal Login)</label>
                <input name="mobile" placeholder="e.g. 9876543210" required>
            </div>
            <div class="field">
                <label>Email Address</label>
                <input name="email" type="email" placeholder="customer@example.com">
            </div>
            <div class="field">
                <label>PAN Card Number</label>
                <input name="pan" placeholder="ABCDE1234F">
            </div>
            <div class="field">
                <label>Date of Birth</label>
                <input name="dob" type="date">
            </div>
            <div class="field full">
                <label>Residential Address</label>
                <textarea name="address" placeholder="Enter complete address"></textarea>
            </div>
        </div>
        <div style="margin-top: 16px; background: rgba(59, 130, 246, 0.1); border: 1px solid var(--primary); padding: 12px 16px; border-radius: 10px; font-size: 0.82rem; color: #60a5fa; margin-bottom: 20px;">
            ℹ️ <strong>Auto Login Credential:</strong> A customer portal user account will be created automatically. Username: <strong>Mobile / Email</strong> | Default Password: <strong>123456</strong>
        </div>
        <button class="btn" style="background: var(--primary); color: #fff;">+ Save Customer & Generate Portal Login</button>
    </form>
</div>
<?php render_end(); ?>
