<?php 
require_once __DIR__.'/includes/auth.php'; 
if (u()) header('Location:' . url('/')); 
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (!empty($loginInput)) {
        $p = db();
        // 1. Search in users table by email or name
        $s = $p->prepare('SELECT * FROM users WHERE (email=? OR name=?) AND status="active" LIMIT 1');
        $s->execute([$loginInput, $loginInput]);
        $x = $s->fetch();

        // 2. If not found in users, search by customer email or mobile or PAN
        if (!$x) {
            $cStmt = $p->prepare('SELECT * FROM customers WHERE email=? OR mobile=? OR pan=? LIMIT 1');
            $cStmt->execute([$loginInput, $loginInput, $loginInput]);
            $cust = $cStmt->fetch();

            if ($cust) {
                // Check if user record exists for customer email
                $custEmail = $cust['email'] ?: ($cust['mobile'] . '@customer.local');
                $uStmt = $p->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
                $uStmt->execute([$custEmail]);
                $x = $uStmt->fetch();

                if (!$x) {
                    // Create customer user record with initial password if not exists
                    $h = password_hash($pass ?: '123456', PASSWORD_DEFAULT);
                    $ins = $p->prepare('INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, "customer", "active")');
                    $ins->execute([$cust['shop_id'], $cust['name'], $custEmail, $h]);
                    $userId = (int)$p->lastInsertId();

                    $x = [
                        'id' => $userId,
                        'shop_id' => $cust['shop_id'],
                        'name' => $cust['name'],
                        'email' => $custEmail,
                        'password' => $h,
                        'role' => 'customer',
                        'status' => 'active',
                        'mobile' => $cust['mobile']
                    ];
                }
            }
        }

        $isValidPassword = false;
        if ($x) {
            if (password_verify($pass, $x['password'] ?? '')) {
                $isValidPassword = true;
            } elseif ($x['role'] === 'customer' && ($pass === '123456' || (!empty($cust['mobile']) && $pass === $cust['mobile']))) {
                $isValidPassword = true;
            }
        }

        if ($x && $isValidPassword) {
            login($x);
            header('Location:' . url('/'));
            exit;
        }
        $err = 'Invalid Email / Mobile Number or Password credentials.';
    } else {
        $err = 'Please enter your email or mobile number.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login · GO4FIN (Go4 Finance Private Limited)</title>
    
    <!-- Google Fonts & Lucide Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="stylesheet" href="<?=url('/public/assets/css/app.css')?>">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 460px;
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        .login-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-header .logo {
            width: 54px;
            height: 54px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.4rem;
            color: #fff;
            margin: 0 auto 16px auto;
            box-shadow: 0 4px 20px var(--primary-glow);
        }
        .login-header h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
        }
        .login-header p {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 4px;
        }
        .alert-danger {
            background: var(--danger-bg);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--danger);
            padding: 12px 16px;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<?php $themeMode = get_setting('theme_mode', 'light'); ?>
<body class="<?=$themeMode === 'light' ? 'light-theme' : ''?>">

    <div class="login-card">
        <div class="login-header">
            <img src="<?=url('/public/assets/images/logo.png')?>" alt="Go4 Finance" style="height: 72px; width: 72px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 18px rgba(0,0,0,0.2); background: #fff; margin-bottom: 12px;">
            <h1>GO4<span>FIN</span></h1>
            <p>Go4 Finance Private Limited — Sign in to ERP or Customer Portal</p>
        </div>

        <?php if($err): ?>
            <div class="alert-danger"><?=$err?></div>
        <?php endif; ?>

        <form method="post">
            <div class="field">
                <label>Email Address or Mobile Number</label>
                <input name="email" id="emailInput" placeholder="Registered Email or 10-digit Mobile" required autocomplete="username">
            </div>
            <div class="field">
                <label>Password</label>
                <input name="password" id="passInput" type="password" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button class="btn" style="width: 100%; margin-top: 10px; padding: 14px;">
                <i data-lucide="log-in"></i> Sign In to Portal
            </button>
        </form>

        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-color); font-size: 0.78rem; text-align: center; color: var(--text-muted); display: flex; align-items: center; justify-content: center; gap: 6px;">
            <i data-lucide="shield-check" style="width: 14px; height: 14px; color: var(--success, #10b981);"></i>
            <span>Authorized Personnel & Customer Access Only</span>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>
</html>
