<?php
require_once __DIR__ . '/../config/config.php';
$p = db();
$rows = $p->query("SELECT setting_key, setting_value FROM settings ORDER BY setting_key")->fetchAll();
foreach ($rows as $r) {
    echo $r['setting_key'] . ' => ' . substr($r['setting_value'], 0, 100) . "\n";
}
