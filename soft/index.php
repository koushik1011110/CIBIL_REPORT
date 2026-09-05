<?php
require_once __DIR__ . '/includes/auth.php';
if (!u()) {
    header('Location: ' . url('/login.php'));
    exit;
}
$r = u()['role'];
$loc = ($r === 'superadmin') ? url('/admin/dashboard.php') : (($r === 'shop_admin') ? url('/shop/dashboard.php') : (($r === 'staff') ? url('/staff/dashboard.php') : url('/customer/dashboard.php')));
header('Location: ' . $loc);
exit;
