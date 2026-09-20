<?php
require_once __DIR__ . '/../config/config.php';
$_SESSION['user'] = ['id' => 1, 'role' => 'superadmin', 'name' => 'Super Admin'];
require_once __DIR__ . '/../includes/layout.php';
ob_start();
start('Dropdown Test');
render_end();
$html = ob_get_clean();

echo "Has reportsNavBtn: " . (strpos($html, 'reportsNavBtn') !== false ? 'YES' : 'NO') . "\n";
echo "Has reportsDropdown: " . (strpos($html, 'id="reportsDropdown"') !== false ? 'YES' : 'NO') . "\n";
echo "Has cmsDropdown: " . (strpos($html, 'id="cmsDropdown"') !== false ? 'YES' : 'NO') . "\n";
echo "Has toggleRealDropdown: " . (strpos($html, 'toggleRealDropdown') !== false ? 'YES' : 'NO') . "\n";
