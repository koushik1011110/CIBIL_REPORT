<?php
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$id = (int)($_GET['id'] ?? 0);
$p = db();

$stmt = $p->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c) {
    header('Location: customers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pan = trim($_POST['pan'] ?? '');
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $address = trim($_POST['address'] ?? '');

    $up = $p->prepare('UPDATE customers SET name=?, mobile=?, email=?, pan=?, dob=?, address=? WHERE id=?');
    $up->execute([$name, $mobile, $email, $pan, $dob, $address, $id]);

    header('Location: customers.php');
    exit;
}

start('Edit Customer');
?>
<div class="card">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px;">Edit Customer Details</h3>
    <form class="form" method="post">
        <div class="form-grid">
            <div class="field">
                <label>Name *</label>
                <input name="name" value="<?=e($c['name'])?>" required>
            </div>
            <div class="field">
                <label>Mobile *</label>
                <input name="mobile" value="<?=e($c['mobile'])?>" required>
            </div>
            <div class="field">
                <label>Email</label>
                <input name="email" type="email" value="<?=e($c['email'])?>">
            </div>
            <div class="field">
                <label>PAN Card Number</label>
                <input name="pan" value="<?=e($c['pan'])?>">
            </div>
            <div class="field">
                <label>Date of Birth</label>
                <input name="dob" type="date" value="<?=e($c['dob'])?>">
            </div>
            <div class="field full">
                <label>Address</label>
                <textarea name="address"><?=e($c['address'])?></textarea>
            </div>
        </div>
        <div style="margin-top: 16px; display: flex; gap: 10px;">
            <button type="submit" class="btn" style="background: var(--primary); color: #fff;">Update Customer</button>
            <a href="customers.php" class="btn" style="background: rgba(255,255,255,0.1);">Cancel</a>
        </div>
    </form>
</div>
<?php render_end(); ?>
