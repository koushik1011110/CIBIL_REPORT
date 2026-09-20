<?php
require_once __DIR__ . '/../config/config.php';
$_SESSION['user'] = ['id' => 1, 'role' => 'superadmin', 'name' => 'Super Admin'];
ob_start();
require __DIR__ . '/../admin/users.php';
$html = ob_get_clean();
echo "Rendered users.php successfully! Length: " . strlen($html) . "\n";
echo "Contains Users Directory: " . (strpos($html, 'User Accounts Directory') !== false ? 'YES' : 'NO') . "\n";
